<?php

namespace lightningsdk\sitemanager\Model;

use lightningsdk\core\Model\ObjectDatabaseStorage;
use lightningsdk\core\Tools\Cache\Cache;
use lightningsdk\core\Tools\ClassLoader;
use lightningsdk\core\Tools\Configuration;
use lightningsdk\core\Tools\Database;
use lightningsdk\core\Tools\Logger;
use lightningsdk\core\Tools\Navigation;
use lightningsdk\core\Tools\Output;
use lightningsdk\core\Tools\Request;
use lightningsdk\core\Tools\Singleton;
use lightningsdk\core\View\CSS;

class SiteOverridable extends Singleton {

    use ObjectDatabaseStorage;

    const TABLE = 'site';
    const PRIMARY_KEY = 'site_id';

    public function __construct($data) {
        $this->__data = $data;
        $this->initJSONEncodedFields();
        if (!$this->loadCachedConfig()) {
            $this->updateConfig();
        }
        $this->cacheConfig();
        $this->reinitLightning();
    }

    /**
     * When the site singleton is initiated, we also check for and handle redirects.
     * Rediercts might also be handled by the router.
     * @return Site
     * @throws \Exception
     */
    public static function createInstance() {
        if (Request::isCLI()) {
            // If it's not found, just return a new object with only the domain.
            return new static(['domain' => 'cli']);
        }

        $domain = static::getDomain();

        // Load the site settings
        if ($site_data = Database::getInstance()->selectRow(static::TABLE, ['domain' => $domain])) {
            // HTTPS Redirect if required
            static::SSLRedirect($site_data);
            return new static($site_data);
        }

        // If he domain does not exist, see if there is a redirect entry and forward it.
        static::checkRedirect($domain);

        throw new \Exception('Domain not configured');
    }

    protected static function getDomain() {
        $domain = strtolower(Request::getDomain());
        Configuration::set('cookie_domain', preg_replace('/:.*/', '', $domain));

        // Load the domain from a cookie in debug mode
        if (Configuration::get('debug')) {
            if ($domain = Request::get('domain')) {
                Output::setCookie('domain', $domain);
            }
            elseif ($cookieDomain = Request::cookie('domain')) {
                $domain = $cookieDomain;
            }
            elseif ($testdomain = Configuration::get('modules.sitemanager.testdomain')) {
                $domain = $testdomain;
            }
        }

        // Remove the www prefix
        $domain = preg_replace('/^www\./', '', $domain);

        return $domain;
    }

    protected function loadCachedConfig() {
        if (!Configuration::get('debug')) {
            $cache = Cache::get(Cache::PERMANENT);
            if ($cached_config = $cache->get($this->domain . '_config')) {
                // override the entire config
                Configuration::override($cached_config);
                return true;
            }
        }
        return false;
    }

    protected function cacheConfig() {
        if (!Configuration::get('debug')) {
            // Not debug mode, save the cache.
            $cache = Cache::get(Cache::PERMANENT);
            $cache->set($this->domain . '_config', Configuration::getConfiguration());
        }
    }

    public function clearCache() {
        if (!Configuration::get('debug')) {
            // Not debug mode, save the cache.
            $cache = Cache::get(Cache::PERMANENT);
            $cache->unset($this->domain . '_config');
        }
    }

    protected function updateConfig() {
        if ($config = Config::loadByID($this->id)) {
            $config = $config->config;

            $overrides = [
                'lightningsdk/blog' => 'lightningsdk/sitemanager-blog',
                'lightningsdk/checkout' => 'lightningsdk/sitemanager-checkout',
            ];

            // Override any customizable configs with the sitemanager version
            foreach ($config['modules']['include'] as $key => $include) {
                if (!empty($overrides[$include])) {
                    $config['modules']['include'][$key] = $overrides[$include];
                }
            }

            if (file_exists(HOME_PATH . '/css/domain/' . $this->domain . '.css')) {
                Configuration::set('modules.site.customDNS', true);
            }

            Configuration::merge($config);
        }
    }

    protected function reinitLightning() {
        // Load modules and reinit the class loader
        Configuration::loadModules(Configuration::get('modules.include'));
        ClassLoader::reloadClasses();

        // The site will have it's own css file with additions to the basics
        if (Configuration::get('modules.site.customDNS')) {
            CSS::add('/css/domain/' . $this->domain . '.css');
        }
    }

    protected static function checkRedirect($domain) {
        // If it's not a site, see if it's a redirect
        if ($redirect = Database::getInstance()->selectRowQuery([
            'from' => 'site_redirect',
            'join' => [
                'join' => static::TABLE,
                'using' => static::PRIMARY_KEY,
            ],
            'select' => ['site.domain'],
            'where' => ['site_redirect.domain' => $domain],
        ])) {
            $source = Request::getURLWithParams();
            $destination = 'http://' . $redirect['domain'];
            Logger::info("Redirecting requested domain from [{$source}] to [{$destination}]");
            Navigation::redirect($destination, [], true);
        }
    }

    protected static function SSLRedirect($site_data) {
        if (!empty($site_data['requires_ssl']) && !Request::isHTTPS() && !Configuration::get('debug')) {
            $params = $_GET;
            unset($params['request']);
            $source = Request::getURLWithParams();
            $destination = str_ireplace('http://', 'https://', Request::getURL());
            Logger::info("Redirecting request for SSL from [{$source}] to [{$destination}]");
            Navigation::redirect($destination, $params, true);
        }
    }
}
