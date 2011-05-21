<?php
/**
 * Piwik - Open source web analytics
 * 
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @version $Id: Controller.php 4515 2011-04-19 23:40:14Z matt $
 * 
 * @category Piwik_Plugins
 * @package Piwik_CoreAdminHome
 */

/**
 *
 * @package Piwik_CoreAdminHome
 */
class Piwik_CoreAdminHome_Controller extends Piwik_Controller_Admin
{
	/**
     * Shows the "Track Visits" checkbox.
     */
    public function optOut()
    {
		$trackVisits = !Piwik_Tracker_IgnoreCookie::isIgnoreCookieFound();

		$nonce = Piwik_Common::getRequestVar('nonce', false);
		$language = Piwik_Common::getRequestVar('language', '');
		if($nonce !== false && Piwik_Nonce::verifyNonce('Piwik_OptOut', $nonce))
		{
			Piwik_Nonce::discardNonce('Piwik_OptOut');
			Piwik_Tracker_IgnoreCookie::setIgnoreCookie();
			$trackVisits = !$trackVisits;
		}
 
		$view = Piwik_View::factory('optOut');
		$view->trackVisits = $trackVisits;
		$view->nonce = Piwik_Nonce::getNonce('Piwik_OptOut', 3600);
		$view->language = Piwik_LanguagesManager_API::getInstance()->isLanguageAvailable($language)
			? $language
			: Piwik_LanguagesManager::getLanguageCodeForCurrentUser();
		echo $view->render();
	}
}