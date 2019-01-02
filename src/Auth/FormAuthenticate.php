<?php
namespace TwoFactorAuth\Auth;

use Cake\Auth\BaseAuthenticate;
use Cake\Controller\ComponentRegistry;
use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\Utility\Hash;
use Cake\Utility\Security;
use Exception;
use TwoFactorAuth\Controller\Component\AuthComponent;

/**
 * Two factor form Authenticate
 */
class FormAuthenticate extends BaseAuthenticate
{
    /**
     * Constructor
     *
     * @param \Cake\Controller\ComponentRegistry $registry The Component registry used on this request.
     * @param array $config Array of config to use.
     */
    public function __construct(ComponentRegistry $registry, array $config = [])
    {
        $this->_defaultConfig['fields']['secret'] = 'secret';
        $this->_defaultConfig['fields']['remember'] = 'remember';
        $this->_defaultConfig['remember'] = false;
        $this->_defaultConfig['cookie'] = [
            'name' => 'TwoFactorAuth',
            'httpOnly' => true,
            'expires' => '+30 days'
        ];
        $this->_defaultConfig['verifyAction'] = [
            'prefix' => null,
            'controller' => 'Users',
            'action' => 'verify',
        ];

        parent::__construct($registry, $config);
    }

    /**
     * Get user's credentials (username and password) from either session or request data
     *
     * @param \Cake\Http\ServerRequest $request Request instance
     * @return array|bool
     */
    protected function _getCredentials(ServerRequest $request)
    {
        $credentials = [];
        foreach (['username', 'password'] as $field) {
            if (!$credentials[$field] = $request->getData($this->_config['fields'][$field])) {
                $credentials[$field] = $this->_decrypt($request->getSession()->read('TwoFactorAuth.credentials.' . $field));
            }

            if (empty($credentials[$field]) || !is_string($credentials[$field])) {
                return false;
            }
        }

        return $credentials;
    }

    /**
     * Verify remember cookie. If cookie not set, verify one-time code. If code not provided - redirect to verifyAction. If code provided and is not valid -
     * set flash message and redirect to verifyAction. Otherwise - return true.
     *
     * @param string $secret user's secret
     * @param string $code one-time code
     * @param \Cake\Http\Response $response response instance
     * @var \TwoFactorAuth\Controller\Component\AuthComponent $Auth used Auth component
     * @return bool
     * @throws \Exception
     */
    protected function _verifyCode($secret, $code, Response $response)
    {
        $Auth = $this->_registry->getController()->Auth;
        if (!($Auth instanceof AuthComponent)) {
            throw new Exception('TwoFactorAuth.Auth component has to be used for authentication.');
        }

        if ($this->getConfig('remember') && $this->_getRememberedSecret() === $secret) {
            return true;
        }

        $verifyAction = Router::url($this->getConfig('verifyAction'), true);

        if ($code === null) {
            $this->_registry->getController()->setResponse($response->withLocation($verifyAction));

            return false;
        }

        if (!$Auth->verifyCode($secret, $code)) {
            $this->_registry->getController()->setResponse($response->withLocation($verifyAction));
            $Auth->Flash->error(__d('TwoFactorAuth', 'Invalid two-step verification code.'), ['key' => 'two-factor-auth']);

            return false;
        }

        return true;
    }

    /**
     * Authenticates the identity contained in a request. Will use the `config.userModel`, and `config.fields`
     * to find POST data that is used to find a matching record in the `config.userModel`. Will return false if
     * there is no post data, either username or password is missing, or if the scope conditions have not been met.
     * If user's secret field is not empty and no on-time submitted - will redirect to verifyAction. If on-time code
     * submitted - will verify the code.
     *
     * @param \Cake\Http\ServerRequest $request The request that contains login information.
     * @param \Cake\Http\Response $response Response object.
     * @return array|bool False on login failure.  An array of User data on success.
     * @throws Exception
     */
    public function authenticate(ServerRequest $request, Response $response)
    {
        if (!$credentials = $this->_getCredentials($request)) {
            return false;
        }

        if (!$user = $this->_findUser($credentials['username'], $credentials['password'])) {
            return false;
        }

        foreach ($credentials as $field => $value) {
            $credentials[$field] = $this->_encrypt($value);
        }

        $secretField = $this->getConfig('fields.secret');
        if ($secret = Hash::get($user, $secretField)) {
            $request->getSession()->write('TwoFactorAuth.credentials', $credentials);
            if (!$this->_verifyCode($secret, $request->getData('code'), $response)) {
                return false;
            }

            if ($this->getConfig('remember') && $request->getData($this->getConfig('fields.remember'))) {
                $this->_setRememberedSecret($secret);
            }

            $request->getSession()->delete('TwoFactorAuth.credentials');
        }

        unset($user[$secretField]);

        return $user;
    }

    /**
     * Encrypt a string
     *
     * @param string $value string to encrypt
     * @return string
     */
    protected function _encrypt($value)
    {
        return base64_encode(
            Security::encrypt($value, $this->_encryptionKey())
        );
    }

    /**
     * Decrypt a base64 encoded string
     *
     * @param string $value string to decrypt
     * @return bool|string
     */
    protected function _decrypt($value)
    {
        if (empty($value)) {
            return false;
        }

        return Security::decrypt(
            base64_decode($value),
            $this->_encryptionKey()
        );
    }

    /**
     * Return the encryption key to use.
     * When a custom key is configured, it is used, otherwise it returns the security salt
     *
     * @return string
     */
    protected function _encryptionKey()
    {
        return Configure::read('TwoFactorAuth.encryptionKey') ?: Security::getSalt();
    }

    /**
     * Return controller CookieComponent. If not loaded, load it
     *
     * @return \Cake\Controller\Component\CookieComponent
     */
    protected function _getCookieComponent()
    {
        if (!$this->_registry->getController()->components()->has('Cookie')) {
            $this->_registry->getController()->loadComponent('Cookie');
        }

        return $this->_registry->getController()->Cookie;
    }

    /**
     * Remember user secret in a cookie
     *
     * @return string
     */
    protected function _getRememberedSecret()
    {
        $cookie = (array)$this->_getCookieComponent()->read($this->getConfig('cookie.name'));

        return Hash::get($cookie, 'secret');
    }

    /**
     * Get remembered user secret from cookie
     *
     * @param string $secret Secret to remember
     */
    protected function _setRememberedSecret($secret)
    {
        $this->_getCookieComponent()->configKey($this->getConfig('cookie.name'), $this->getConfig('cookie'));
        $this->_getCookieComponent()->write($this->getConfig('cookie.name'), compact('secret'));
    }
}
