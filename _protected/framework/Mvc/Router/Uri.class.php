<?php
/**
 * Uri Router for URLs rewrite.
 *
 * @author           Pierre-Henry Soria <ph7software@gmail.com>
 * @copyright        (c) 2012-2018, Pierre-Henry Soria. All Rights Reserved.
 * @license          GNU General Public License; See PH7.LICENSE.txt and PH7.COPYRIGHT.txt in the root directory.
 * @package          PH7 / Framework / Mvc / Router
 */

namespace PH7\Framework\Mvc\Router;

defined('PH7') or exit('Restricted access');

use DOMDocument;
use DOMElement;
use PH7\Framework\Cache\Cache;
use PH7\Framework\File\Exception as FileException;
use PH7\Framework\Parse\Url;
use PH7\Framework\Pattern\Statik;

class Uri
{
    const CACHE_GROUP = 'str/uri/' . PH7_LANG_CODE;
    const CACHE_TIME = 86400; // 24h

    // Set to FALSE if you got too many files (e.g. if inode files usage is overaged on shared hosting)
    const URI_CACHE_ENABLED = true;

    const ROUTE_FILE_EXT = '.xml';

    /** @var bool */
    private static $bFullClean;

    /**
     * Import the trait to set the class static.
     * The trait sets constructor/clone private to prevent instantiation.
     */
    use Statik;

    /**
     * Load route file.
     *
     * @param DOMDocument $oDom
     *
     * @return DOMDocument
     *
     * @throws FileException If the file is not found.
     */
    public static function loadFile(DOMDocument $oDom)
    {
        $oCache = (new Cache)->start(self::CACHE_GROUP, 'routefile', self::CACHE_TIME);

        if (!$sContents = $oCache->get()) {
            $sContents = file_get_contents(self::getRouteFilePath()); // Get the XML contents
            $sContents = self::parseVariable($sContents); // Parse the variables
            $oCache->put($sContents);
        }
        unset($oCache);

        $oDom->loadXML($sContents); // Load the XML contents

        return $oDom;
    }

    /**
     * @param string $sModule
     * @param string $sController
     * @param string $sAction
     * @param string $sVars Default NULL
     * @param bool $bFullClean Default TRUE
     *
     * @return string
     *
     * @throws FileException
     */
    public static function get($sModule, $sController, $sAction, $sVars = null, $bFullClean = true)
    {
        self::$bFullClean = $bFullClean;

        // Caching URI function will speed up the website ~500ms faster (up to 1.4s!)
        $sCacheId = 'geturi' . $sModule . $sController . $sAction . $sVars;
        $oCache = (new Cache)->start(self::CACHE_GROUP, $sCacheId, self::CACHE_TIME);
        $oCache->enabled(static::URI_CACHE_ENABLED);

        if (!$sUrl = $oCache->get()) {
            $sUrl = self::uri(['module' => $sModule, 'controller' => $sController, 'action' => $sAction, 'vars' => $sVars]);
            $oCache->put($sUrl);
        }
        unset($oCache);

        return $sUrl;
    }

    /**
     * @param string $sLangCode The two-letter language code. e.g., en, fr, de, ru, ...
     *
     * @return bool
     */
    private static function doesLangRouteFileExist($sLangCode)
    {
        return is_file(PH7_PATH_APP_CONFIG . 'routes/' . $sLangCode . self::ROUTE_FILE_EXT);
    }

    /**
     * @param array $aParams
     *
     * @return string
     *
     * @throws FileException If the XML file is not found.
     */
    private static function uri(array $aParams)
    {
        $sModule = $aParams['module'];
        $sController = $aParams['controller'];
        $sAction = $aParams['action'];

        $sVars = self::areVariablesSet($aParams) ? self::getVariables($aParams['vars']) : '';

        $oUrl = static::loadFile(new DOMDocument);
        foreach ($oUrl->getElementsByTagName('route') as $oRoute) {
            if (
                preg_match('#^' . $oRoute->getAttribute('module') . '$#', $sModule) &&
                preg_match('#^' . $oRoute->getAttribute('controller') . '$#', $sController) &&
                preg_match('#^' . $oRoute->getAttribute('action') . '$#', $sAction)
            ) {
                $sUri = self::stripSpecialCharacters($oRoute);

                return PH7_URL_ROOT . $sUri . $sVars;
            }
        }
        unset($oUrl);

        return PH7_URL_ROOT . "$sModule/$sController/$sAction$sVars";
    }

    /**
     * @return string XML route filename.
     *
     * @throws FileException If the file is not found.
     */
    private static function getRouteFilePath()
    {
        $sPathDefaultLang = PH7_PATH_APP_CONFIG . 'routes/' . PH7_DEFAULT_LANG_CODE . self::ROUTE_FILE_EXT;

        if (self::doesLangRouteFileExist(PH7_LANG_CODE)) {
            return PH7_PATH_APP_CONFIG . 'routes/' . PH7_LANG_CODE . self::ROUTE_FILE_EXT;
        }

        if (self::doesLangRouteFileExist(PH7_DEFAULT_LANG_CODE)) {
            return $sPathDefaultLang;
        }

        throw new FileException('XML route file not found: ' . $sPathDefaultLang);
    }

    /**
     * Parse the variables route.
     *
     * @param string $sContents
     *
     * @return string The contents parsed.
     */
    private static function parseVariable($sContents)
    {
        /**
         * Replace the "[$page_ext]" variable by the "PH7_PAGE_EXT" constant.
         *
         * @internal We add a slash for RegEx ignores the dot (e.g., '.'html), (in RegEx, the dot means "any single character").
         */
        $sContents = str_replace('[$page_ext]', '\\' . PH7_PAGE_EXT, $sContents);

        /**
         * Replace the "[$admin_mod]" variable by the "PH7_ADMIN_MOD" constant.
         */
        $sContents = str_replace('[$admin_mod]', PH7_ADMIN_MOD, $sContents);

        return $sContents;
    }

    /**
     * @param array $aParams
     *
     * @return bool
     */
    private static function areVariablesSet(array $aParams)
    {
        return !empty($aParams['vars']);
    }

    /**
     * @param string $sVariables
     *
     * @return string
     */
    private static function getVariables($sVariables)
    {
        // Omit commas which may be part of a sentence in the URL parameters
        $sVariables = str_replace([', ', ' ,'], '', $sVariables);

        $sVars = '';
        $aVars = explode(',', $sVariables);
        foreach ($aVars as $sVar) {
            $sVars .= PH7_SH . $sVar;
        }
        unset($aVars);

        return Url::clean($sVars, self::$bFullClean);
    }

    /**
     * Strip the special characters from the URI.
     *
     * @param DOMElement $oRoute
     *
     * @return string
     */
    private static function stripSpecialCharacters(DOMElement $oRoute)
    {
        $sUri = $oRoute->getAttribute('url');
        $sUri = str_replace('\\', '', $sUri);
        $sUri = preg_replace('#\(.+\)#', '', $sUri);
        $sUri = preg_replace('#([/\?]+)$#', '', $sUri);

        return $sUri;
    }
}
