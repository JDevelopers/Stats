<?php
/**
 * Piwik - Open source web analytics
 * 
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @version $Id: Action.php 4389 2011-04-10 23:36:30Z matt $
 * 
 * @category Piwik
 * @package Piwik
 */

/**
 * Interface of the Action object.
 * New Action classes can be defined in plugins and used instead of the default one.
 * 
 * @package Piwik
 * @subpackage Piwik_Tracker
 */
interface Piwik_Tracker_Action_Interface {
	const TYPE_ACTION_URL   = 1;
	const TYPE_OUTLINK  = 2;
	const TYPE_DOWNLOAD = 3;
	const TYPE_ACTION_NAME = 4;
	
	public function setRequest($requestArray);
	public function setIdSite( $idSite );
	public function init();
	public function getActionUrl();
	public function getActionName();
	public function getActionType();
	public function record( $idVisit, $visitorIdCookie, $idRefererActionUrl, $idRefererActionName, $timeSpentRefererAction );
	public function getIdActionUrl();
	public function getIdActionName();
	public function getIdLinkVisitAction();
}

/**
 * Handles an action (page view, download or outlink) by the visitor.
 * Parses the action name and URL from the request array, then records the action in the log table.
 * 
 * @package Piwik
 * @subpackage Piwik_Tracker
 */
class Piwik_Tracker_Action implements Piwik_Tracker_Action_Interface
{
	private $request;
	private $idSite;
	private $timestamp;
	private $idLinkVisitAction;
	private $idActionName = null;
	private $idActionUrl = null;

	private $actionName;
	private $actionType;
	private $actionUrl;
	
	static private $queryParametersToExclude = array('phpsessid', 'jsessionid', 'sessionid', 'aspsessionid');
	
	public function setRequest($requestArray)
	{
		$this->request = $requestArray;
	}

	public function getRequest()
	{
	    return $this->request;
	}
	
	/**
	 * Returns URL of the page currently being tracked, or the file being downloaded, or the outlink being clicked
	 * @return string
	 */
	public function getActionUrl()
	{
		return $this->actionUrl;
	}
	public function getActionName()
	{
		return $this->actionName;
	}
	public function getActionType()
	{
		return $this->actionType;
	}
	public function getActionNameType()
	{
		$actionNameType = null;

		// we can add here action types for names of other actions than page views (like downloads, outlinks)
		switch( $this->getActionType() )
		{
			case Piwik_Tracker_Action_Interface::TYPE_ACTION_URL:
				$actionNameType = Piwik_Tracker_Action_Interface::TYPE_ACTION_NAME;
				break;
		}

		return $actionNameType;
	}

	public function getIdActionUrl()
	{
		return $this->idActionUrl;
	}
	public function getIdActionName()
	{
		return $this->idActionName;
	}
	
	protected function setActionName($name)
	{
		$name = $this->cleanupString($name);
		$this->actionName = $name;
	}
	
	protected function setActionType($type)
	{
		$this->actionType = $type;
	}
	
	protected function setActionUrl($url)
	{
		$url = self::excludeQueryParametersFromUrl($url, $this->idSite);
		$this->actionUrl = $url;
	}
	
	static public function excludeQueryParametersFromUrl($originalUrl, $idSite)
	{
		$website = Piwik_Common::getCacheWebsiteAttributes( $idSite );
		$originalUrl = Piwik_Common::unsanitizeInputValue($originalUrl);
		$originalUrl = self::cleanupString($originalUrl);
		$parsedUrl = @parse_url($originalUrl);
		if(empty($parsedUrl['query']))
		{
			return $originalUrl;
		}
		$campaignTrackingParameters = Piwik_Common::getCampaignParameters();
		
		$campaignTrackingParameters = array_merge(
				$campaignTrackingParameters[0], // campaign name parameters
				$campaignTrackingParameters[1] // campaign keyword parameters
		);	
				
		$excludedParameters = isset($website['excluded_parameters']) 
									? $website['excluded_parameters'] 
									: array();
									
		$parametersToExclude = array_merge( $excludedParameters, 
											self::$queryParametersToExclude,
											$campaignTrackingParameters);
											
		$parametersToExclude = array_map('strtolower', $parametersToExclude);
		$queryParameters = Piwik_Common::getArrayFromQueryString($parsedUrl['query']);
		
		$validQuery = '';
		$separator = '&';
		foreach($queryParameters as $name => $value)
		{
			if(!in_array(strtolower($name), $parametersToExclude))
			{
				if (is_array($value))
				{
					foreach ($value as $param)
					{
						if($param === false)
						{
							$validQuery .= $name.'[]'.$separator;
						}
						else
						{
							$validQuery .= $name.'[]='.$param.$separator;
						}
					}
				}
				else if($value === false)
				{
					$validQuery .= $name.$separator;
				}
				else
				{
					$validQuery .= $name.'='.$value.$separator;
				}
			}
		}
		$parsedUrl['query'] = substr($validQuery,0,-strlen($separator));
		$url = Piwik_Common::getParseUrlReverse($parsedUrl);
		printDebug('Excluded parameters "'.implode(',',$excludedParameters).'" from URL');
		printDebug(' Before was "'.$originalUrl.'"');
		printDebug(' After is "'.$url.'"');
		return $url;
	}
	
	public function init()
	{
		$info = $this->extractUrlAndActionNameFromRequest();
		$this->setActionName($info['name']);
		$this->setActionType($info['type']);
		$this->setActionUrl($info['url']);
	}
	
	static public function getSqlSelectActionId()
	{
		$sql = "SELECT idaction, type 
							FROM ".Piwik_Common::prefixTable('log_action')
						."  WHERE "
						."		( hash = CRC32(?) AND name = ? AND type = ? ) ";
		return $sql;
	}
	/**
	 * Loads the idaction of the current action name and the current action url.
	 * These idactions are used in the visitor logging table to link the visit information
	 * (entry action, exit action) to the actions.
	 * These idactions are also used in the table that links the visits and their actions.
	 * 
	 * The methods takes care of creating a new record(s) in the action table if the existing
	 * action name and action url doesn't exist yet.
	 * 
	 */
	function loadIdActionNameAndUrl()
	{
		if( !is_null($this->idActionUrl) && !is_null($this->idActionName) )
		{
			return;
		}
		$idAction = Piwik_Tracker::getDatabase()->fetchAll(
						$this->getSqlSelectActionId()
						."	OR "
						."		( hash = CRC32(?) AND name = ? AND type = ? ) ",
						array($this->getActionName(), $this->getActionName(), $this->getActionNameType(),
							$this->getActionUrl(), $this->getActionUrl(), $this->getActionType())
					);

		if( $idAction !== false )
		{
			foreach($idAction as $row)
			{
				if( $row['type'] == Piwik_Tracker_Action_Interface::TYPE_ACTION_NAME )
				{
					$this->idActionName = $row['idaction'];
				}
				else
				{
					$this->idActionUrl = $row['idaction'];
				}
			}
		}

		$sql = "INSERT INTO ". Piwik_Common::prefixTable('log_action'). 
				"( name, hash, type ) VALUES (?,CRC32(?),?)";

		if( is_null($this->idActionName) 
		    && !is_null($this->getActionNameType()) )
		{
			Piwik_Tracker::getDatabase()->query($sql,
				array($this->getActionName(), $this->getActionName(), $this->getActionNameType()));
			$this->idActionName = Piwik_Tracker::getDatabase()->lastInsertId();
			printDebug("Recording a new page name in the lookup table: ". $this->idActionName);
		}

		if( is_null($this->idActionUrl) )
		{
			Piwik_Tracker::getDatabase()->query($sql,
				array($this->getActionUrl(), $this->getActionUrl(), $this->getActionType()));
			$this->idActionUrl = Piwik_Tracker::getDatabase()->lastInsertId();
			printDebug("Recording a new page URL in the lookup table: ". $this->idActionUrl);
		}
	}
	
	/**
	 * @param int $idSite
	 */
	function setIdSite($idSite)
	{
		$this->idSite = $idSite;
	}
	
	function setTimestamp($timestamp)
	{
		$this->timestamp = $timestamp;
	}
	
	
	/**
	 * Records in the DB the association between the visit and this action.
	 * 
	 * @param int idVisit is the ID of the current visit in the DB table log_visit
	 * @param int idRefererActionUrl is the ID of the last action done by the current visit. 
	 * @param int timeSpentRefererAction is the number of seconds since the last action was done. 
	 * 				It is directly related to idRefererActionUrl.
	 */
	 public function record( $idVisit, $visitorIdCookie, $idRefererActionUrl, $idRefererActionName, $timeSpentRefererAction)
	 {
		$this->loadIdActionNameAndUrl();
		$idActionName = $this->getIdActionName();
		if(is_null($idActionName))
		{
			$idActionName = 0;
		}
		Piwik_Tracker::getDatabase()->query( 
						"INSERT INTO ".Piwik_Common::prefixTable('log_link_visit_action')
						." (idvisit, idsite, idvisitor, server_time, idaction_url, idaction_name, idaction_url_ref, idaction_name_ref, time_spent_ref_action) 
							VALUES (?,?,?,?,?,?,?,?,?)",
					array(	$idVisit, 
							$this->idSite, 
							$visitorIdCookie,
							Piwik_Tracker::getDatetimeFromTimestamp($this->timestamp),
							$this->getIdActionUrl(), 
							$idActionName , 
							$idRefererActionUrl, 
							$idRefererActionName, 
							$timeSpentRefererAction
		));
		
		$this->idLinkVisitAction = Piwik_Tracker::getDatabase()->lastInsertId(); 
		
		$info = array( 
			'idSite' => $this->idSite, 
			'idLinkVisitAction' => $this->idLinkVisitAction, 
			'idVisit' => $idVisit, 
			'idRefererActionUrl' => $idRefererActionUrl, 
			'idRefererActionName' => $idRefererActionName, 
			'timeSpentRefererAction' => $timeSpentRefererAction, 
		); 
		printDebug($info);

		/* 
		* send the Action object ($this)  and the list of ids ($info) as arguments to the event 
		*/ 
		Piwik_PostEvent('Tracker.Action.record', $this, $info);
	 }
	 
	/**
	 * Returns the ID of the newly created record in the log_link_visit_action table
	 *
	 * @return int | false
	 */
	public function getIdLinkVisitAction()
	{
		return $this->idLinkVisitAction;
	}
	
	 /**
	 * Generates the name of the action from the URL or the specified name.
	 * Sets the name as $this->actionName
	  *
	 * @return array
	 */
	protected function extractUrlAndActionNameFromRequest()
	{
		$actionName = null;
		
		// download?
		$downloadUrl = Piwik_Common::getRequestVar( 'download', '', 'string', $this->request);
		if(!empty($downloadUrl))
		{
			$actionType = self::TYPE_DOWNLOAD;
			$url = $downloadUrl;
		}
		
		// outlink?
		if(empty($actionType))
		{
			$outlinkUrl = Piwik_Common::getRequestVar( 'link', '', 'string', $this->request);
			if(!empty($outlinkUrl))
			{
				$actionType = self::TYPE_OUTLINK;
				$url = $outlinkUrl;
			}
		}

		// handle encoding
		$actionName = Piwik_Common::getRequestVar( 'action_name', '', 'string', $this->request);

		// defaults to page view 
		if(empty($actionType))
		{
			$actionType = self::TYPE_ACTION_URL;
			$url = Piwik_Common::getRequestVar( 'url', '', 'string', $this->request);

			// get the delimiter, by default '/'; BC, we read the old action_category_delimiter first (see #1067) 
			$actionCategoryDelimiter = isset(Piwik_Tracker_Config::getInstance()->General['action_category_delimiter'])
										? Piwik_Tracker_Config::getInstance()->General['action_category_delimiter']
										: Piwik_Tracker_Config::getInstance()->General['action_url_category_delimiter'];
			
			// create an array of the categories delimited by the delimiter
			$split = explode($actionCategoryDelimiter, $actionName);
			
			// trim every category
			$split = array_map('trim', $split);
			
			// remove empty categories
			$split = array_filter($split, 'strlen');
			
			// rebuild the name from the array of cleaned categories
			$actionName = implode($actionCategoryDelimiter, $split);
		}
		$url = self::cleanupString($url);
		$actionName = self::cleanupString($actionName);

		return array(
			'name' => empty($actionName) ? '' : $actionName,
			'type' => $actionType,
			'url'  => $url,
		);
	}
	
	protected static function cleanupString($string)
	{
		$string = trim($string);
		$string = str_replace(array("\n", "\r"), "", $string);
		$limit = Piwik_Tracker_Config::getInstance()->Tracker['page_maximum_length'];
		return substr($string, 0, $limit);
	}
}
