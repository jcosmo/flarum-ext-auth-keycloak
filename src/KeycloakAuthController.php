<?php

namespace SpookyGames\Auth\Keycloak;

use Exception;
use Flarum\Group\GroupRepository;
use Flarum\Forum\Auth\Registration;
use Flarum\Forum\Auth\ResponseFactory;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\LoginProvider;
use Flarum\User\User;
use Flarum\User\RegistrationToken;
use Flarum\User\Command\EditUser;
use Flarum\User\Command\RegisterUser;
use Illuminate\Contracts\Bus\Dispatcher;
use League\OAuth2\Client\Token\AccessToken;
use Stevenmaguire\OAuth2\Client\Provider\Keycloak;
use Stevenmaguire\OAuth2\Client\Provider\KeycloakResourceOwner;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Zend\Diactoros\Response\RedirectResponse;

class KeycloakAuthController implements RequestHandlerInterface
{
    /**
     * @var ResponseFactory
     */
    protected $response;

    /**
     * @var SettingsRepositoryInterface
     */
    protected $settings;

    /**
     * @var GroupRepository
     */
    protected $groupRepository;

    /**
     * @var Dispatcher
     */
     protected $bus;

    /**
     * @param ResponseFactory $response
     * @param SettingsRepositoryInterface $settings
     * @param GroupRepository $groupRepository
     * @param Dispatcher $bus
     */
    public function __construct(ResponseFactory $response, SettingsRepositoryInterface $settings, GroupRepository $groupRepository, Dispatcher $bus)
    {
        $this->response = $response;
        $this->settings = $settings;
        $this->groupRepository = $groupRepository;
        $this->bus = $bus;
    }

    /**
     * @param Request $request
     * @return ResponseInterface
     * @throws Exception
     */
    public function handle(Request $request): ResponseInterface
    {
        $conf = app('flarum.config');
        $redirectUri = $conf['url'] . "/auth/keycloak";

        $provider = new Keycloak([
                'authServerUrl'         => $this->settings->get('spookygames-auth-keycloak.server_url'),
                'realm'                 => $this->settings->get('spookygames-auth-keycloak.realm'),
                'clientId'              => $this->settings->get('spookygames-auth-keycloak.app_id'),
                'clientSecret'          => $this->settings->get('spookygames-auth-keycloak.app_secret'),
                'redirectUri'           => $redirectUri,
                'encryptionAlgorithm'   => $this->settings->get('spookygames-auth-keycloak.encryption_algorithm'),
                'encryptionKey'         => $this->settings->get('spookygames-auth-keycloak.encryption_key')
            ]);

        $session = $request->getAttribute('session');
        $queryParams = $request->getQueryParams();

        $code = array_get($queryParams, 'code');

        if (! $code) {
            // If we don't have an authorization code then get one
            $authUrl = $provider->getAuthorizationUrl();
            $session->put('oauth2state', $provider->getState());
            header('Location: '.$authUrl);

            return new RedirectResponse($authUrl);
        }

        $state = array_get($queryParams, 'state');

        // Check given state against previously stored one to mitigate CSRF attack
        if (! $state || $state !== $session->get('oauth2state')) {
            $session->remove('oauth2state');

            throw new Exception('Invalid state');
        }

        // Try to get an access token (using the authorization code grant)
        try {
            $token = $provider->getAccessToken('authorization_code', compact('code'));
        } catch (Exception $e) {
            exit('Failed to get access token: '.$e->getMessage());
        }

        // We got an access token, let's get user details
        try {

            /** @var KeycloakResourceOwner $user */
            $remoteUser = $provider->getResourceOwner($token);

        } catch (Exception $e) {
            exit('Failed to get resource owner: '.$e->getMessage());
        }

        // Fine! We now know everything we need about our remote user
        $remoteUserArray = $remoteUser->toArray();
        $groups = [];

        // Map Keycloak roles onto Flarum groups
        if (isset($remoteUserArray['roles']) && is_array($remoteUserArray['roles'])) {

            if($roleMapping = json_decode($this->settings->get('spookygames-auth-keycloak.role_mapping'), true)) {

                foreach ($remoteUserArray['roles'] as $role) {
                    if ($groupName = array_get($roleMapping, $role)) {
                        if ($group = $this->groupRepository->findByName($groupName)) {
                            $groups[] = array('id' => $group->id);
                        }
                    }
                }
            }
        }

      if ($localUser = LoginProvider::logIn('keycloak', $remoteUser->getId())) {
            // User already exists and is synced with Keycloak

            // Update with latest information

            $registration = $this->decorateRegistration(new Registration, $remoteUser);

            $data = [
                'attributes' => array_merge($registration->getProvided(), $registration->getSuggested()),
                'relationships' => array('groups' => array('data' => $groups))
            ];

            try {
                // Update user
                $this->bus->dispatch(new EditUser($localUser->id, $this->findFirstAdminUser(), $data));
            } catch (Exception $e) {
                error_log('Failed to update Flarum user: '.$e->getMessage());
            }
        }

        $actor = $request->getAttribute('actor');

        return $this->response->make(
            'keycloak', $remoteUser->getId(),
            function (Registration $registration) use ($remoteUser, $groups, $actor) {

                $registration = $this->decorateRegistration($registration, $remoteUser);

                $provided = $registration->getProvided();

                $adminActor = $this->findFirstAdminUser();

                if ($localUser = User::where(array_only($provided, 'email'))->first()) {

                    // User already exists but not synced with Keycloak

                    // Update with latest information
                    $data = [
                        'attributes' => array_merge($provided, $registration->getSuggested()),
                        'relationships' => array('groups' => array('data' => $groups))
                    ];

                    try {
                        // Update user
                        $this->bus->dispatch(new EditUser($localUser->id, $adminActor, $data));
                    } catch (Exception $e) {
                        exit('Failed to update Flarum user: '.$e->getMessage());
                    }

                } else {

                    // User does not exist (yet)
                    // Automatically create it

                    $registrationToken = RegistrationToken::generate('keycloak', $remoteUser->getId(), $provided, $registration->getPayload());
                    $registrationToken->save();

                    $data = [
                        'attributes' => array_merge($provided, $registration->getSuggested(), [
                                'token' => $registrationToken->token,
                                'provided' => array_keys($provided)
                            ]),
                        'relationships' => array('groups' => array('data' => $groups))
                    ];

                    try {
                        // Create user
                        $created = $this->bus->dispatch(new RegisterUser($actor, $data));

                        // Edit user afterwards in order to propagate groups too
                        $this->bus->dispatch(new EditUser($created->id, $adminActor, $data));

                        // Remove its new login provider (will be re-created right afterwards)
                        $created->loginProviders()->delete();
                    } catch (Exception $e) {
                        error_log('Failed to create Flarum user: '.$e->getMessage());
                    }

                }
            }
        );
    }

   public function decorateRegistration(Registration $registration, KeycloakResourceOwner $remoteUser): Registration
   {
        $remoteUserArray = $remoteUser->toArray();

       $registration
           ->provideTrustedEmail($remoteUser->getEmail())
           ->suggestUsername(array_get($remoteUserArray, 'preferred_username'))
           ->setPayload($remoteUserArray);

       $pic = array_get($remoteUserArray, 'picture');
       if ($pic) {
           $registration->provideAvatar($pic);
       }

       return $registration;
   }

    public function findFirstAdminUser(): User
    {
        return $this->groupRepository->findOrFail('1')->users()->first();
    }
}
