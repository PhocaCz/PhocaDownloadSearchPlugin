<?php
/* @package Joomla
 * @copyright Copyright (C) Open Source Matters. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 * @extension Phoca Extension
 * @copyright Copyright (C) Jan Pavelka www.phoca.cz
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

defined( '_JEXEC' ) or die( 'Restricted access' );
if(!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
jimport( 'joomla.plugin.plugin' );

if (!JComponentHelper::isEnabled('com_phocadownload', true)) {
	return JError::raiseError(JText::_('Phoca Download Error'), JText::_('Phoca Download is not installed on your system'));
}
if (! class_exists('PhocaDownloadLoader')) {
    require_once( JPATH_ADMINISTRATOR.DS.'components'.DS.'com_phocadownload'.DS.'libraries'.DS.'loader.php');
}
phocadownloadimport('phocadownload.path.route');
phocadownloadimport('phocadownload.access.access');


class plgSearchPhocaDownload extends JPlugin
{


	public function __construct(& $subject, $config) {
		parent::__construct($subject, $config);
		$this->loadLanguage();
	}
	
	function onContentSearchAreas() {
		static $areas = array(
			'phocadownload' => 'PLG_SEARCH_PHOCADOWNLOAD_PHOCADOWNLOAD'
			);
			return $areas;
	}



	function onContentSearch( $text, $phrase = '', $ordering = '', $areas = null ) {
	
		$db		= JFactory::getDbo();
		$app	= JFactory::getApplication();
		$user	= JFactory::getUser();
		$groups	= implode(',', $user->getAuthorisedViewLevels());
		
		$component			= 'com_phocadownload';
		$paramsC			= JComponentHelper::getParams($component) ;
		
		$display_access_category 		= $paramsC->get( 'display_access_category', 1 );
		if (is_array( $areas )) {
			if (!array_intersect( $areas, array_keys( $this->onContentSearchAreas() ) )) {
				return array();
			}
		}

		$limit			= $this->params->get('search_limit', 20);
		$link_refer		= $this->params->get('link_refer', 0);// 0 category, 1 file view

		$text = trim( $text );
		if ($text == '') {
			return array();
		}

		$section = JText::_( 'PLG_SEARCH_PHOCADOWNLOAD_PHOCADOWNLOAD');
		
		
		switch ($ordering)
		{
			case 'oldest':
				$order = 'a.date ASC';
				break;

			case 'popular':
				$order = 'a.hits DESC';
				break;

			case 'alpha':
				$order = 'a.title ASC';
				break;

			case 'category':
				$order = 'a.title ASC';
				break;

			case 'newest':
			default:
				$order = 'a.date DESC';
		}
		
		// Date in Where
		$jnow		= JFactory::getDate();
		$now		= $jnow->toSql();
		$nullDate	= $db->getNullDate();
		
		$wheres	= array();
		switch ($phrase)
		{
			case 'exact':
				$text		= $db->quote('%'.$db->escape($text, true).'%', false);
				$wheres2	= array();
				$wheres2[]	= 'a.title LIKE '.$text;
				$wheres2[]	= 'a.alias LIKE '.$text;
				//$wheres2[]	= 'a.name LIKE '.$text;
				$wheres2[]	= 'a.metakey LIKE '.$text;
				$wheres2[]	= 'a.metadesc LIKE '.$text;
				$wheres2[]	= 'a.description LIKE '.$text;
				$where		= '(' . implode(') OR (', $wheres2) . ')';
				break;

			case 'all':
			case 'any':
			default:
				$words	= explode(' ', $text);
				$wheres = array();
				foreach ($words as $word)
				{
					$word		= $db->quote('%'.$db->escape($word, true).'%', false);
					$wheres2	= array();
					$wheres2[]	= 'a.title LIKE '.$word;
					$wheres2[]	= 'a.alias LIKE '.$word;
					//$wheres2[]	= 'a.name LIKE '.$word;
					$wheres2[]	= 'a.metakey LIKE '.$word;
					$wheres2[]	= 'a.metadesc LIKE '.$word;
					$wheres2[]	= 'a.description LIKE '.$word;
					$wheres[]	= implode(' OR ', $wheres2);
				}
				$where	= '(' . implode(($phrase == 'all' ? ') AND (' : ') OR ('), $wheres) . ')';
				break;
		}
		
		$rows = array();
		// - - - - - -
		// Categories
		// - - - - - -		
		$query	= $db->getQuery(true);
		$query->select('a.id, a.title AS title, a.alias, a.date AS created, a.access, a.accessuserid,'
		. ' CASE WHEN CHAR_LENGTH(a.alias) THEN CONCAT_WS(\':\', a.id, a.alias) ELSE a.id END as slug,'
		. ' a.description AS text,'
		. ' CONCAT_WS( " / ", '.$db->quote($section).', a.title ) AS section,'
		. ' "2" AS browsernav');
		$query->from('#__phocadownload_categories AS a');
		//$query->innerJoin('#__categories AS c ON c.id = a.catid');
		//$query->where('('.$where.')' . ' AND a.state in ('.implode(',',$state).') AND  a.published = 1 AND a.approved = 1 AND  a.access IN ('.$groups.')');
		$query->where('('.$where.')' . ' AND  a.published = 1 AND  a.access IN ('.$groups.')');
		$query->group('a.id');
		$query->order($order);
			
		// Filter by language
		if ($app->isSite() && $app->getLanguageFilter()) {
			$tag = JFactory::getLanguage()->getTag();
			$query->where('a.language in (' . $db->quote($tag) . ',' . $db->quote('*') . ')');
			//$query->where('c.language in (' . $db->quote($tag) . ',' . $db->quote('*') . ')');
		}
		
		$db->setQuery( $query, 0, $limit );
		$listCategories = $db->loadObjectList();
		$limit -= count($listCategories);
		
	

		if(isset($listCategories)) {
			foreach($listCategories as $key => $value) {
				
				// USER RIGHT - ACCESS - - - - - - - - - - - 
				$rightDisplay = 1;//default is set to 1 (all users can see the category)
		
				if (!empty($value)) {
					$rightDisplay = PhocaDownloadAccess::getUserRight('accessuserid', $value->accessuserid, $value->access, $user->getAuthorisedViewLevels(), $user->get('id', 0), $display_access_category);
				}
				if ($rightDisplay == 0) {
					unset($listCategories[$key]);
				} else {
					$listCategories[$key]->href = $link = JRoute::_(PhocaDownloadRoute::getCategoryRoute($value->id, $value->alias));
				}	
				// - - - - - - - - - - - - - - - - - - - - - 
			}
		}
		$rows[] = $listCategories;

		// Files
		$wheres		= array();
		$wheres3	= array();
		switch ($phrase)
		{
			case 'exact':
				$text		= $db->quote('%'.$db->escape($text, true).'%', false);
				$wheres2	= array();
				$wheres2[]	= 'a.title LIKE '.$text;
				$wheres2[]	= 'a.alias LIKE '.$text;
				$wheres2[]	= 'a.filename LIKE '.$text;
				$wheres2[]	= 'a.metakey LIKE '.$text;
				$wheres2[]	= 'a.metadesc LIKE '.$text;
				$wheres2[]	= 'a.description LIKE '.$text;
				
				$wheres3[]	= '(' . implode(') OR (', $wheres2) . ')';
				break;

			case 'all':
			case 'any':
			default:
				$words	= explode(' ', $text);
				$wheres = array();
				foreach ($words as $word)
				{
					$word		= $db->quote('%'.$db->escape($word, true).'%', false);
					$wheres2	= array();
					$wheres2[]	= 'a.title LIKE '.$word;
					$wheres2[]	= 'a.alias LIKE '.$word;
					$wheres2[]	= 'a.filename LIKE '.$word;
					$wheres2[]	= 'a.metakey LIKE '.$word;
					$wheres2[]	= 'a.metadesc LIKE '.$word;
					$wheres2[]	= 'a.description LIKE '.$word;
					
					$wheres[]	= implode(' OR ', $wheres2);
				}
				$wheres3[]	= '(' . implode(($phrase == 'all' ? ') AND (' : ') OR ('), $wheres) . ')';
				break;
		}
		
		$wheres3[] = '( (unaccessible_file = 1 ) OR (unaccessible_file = 0 AND a.access IN ('.$groups.') ) )';
		$wheres3[] = '( (unaccessible_file = 1 ) OR (unaccessible_file = 0 AND c.access IN ('.$groups.') ) )';
		$wheres3[] 	= ' ( a.publish_up = '.$db->quote($nullDate).' OR a.publish_up <= '.$db->quote($now).' )';
		$wheres3[] 	= ' ( a.publish_down = '.$db->quote($nullDate).' OR a.publish_down >= '.$db->quote($now).' )';
		
		$where = implode(' AND ', $wheres3);
		
		if ( $limit > 0 ) {			
			
			// - - - - - -
			// Files
			// - - - - - -		
			$query	= $db->getQuery(true);
			$query->select(' CASE WHEN CHAR_LENGTH(a.title) THEN CONCAT_WS(\': \', c.title, a.title)
		ELSE c.title END AS title, '
			. ' CASE WHEN CHAR_LENGTH(a.description) THEN CONCAT_WS(\': \', a.title,
		a.description) ELSE a.title END AS text, '
			. ' a.id, a.alias, a.date AS created, c.accessuserid as accessuserid, c.access as cataccess,'
			. ' CASE WHEN CHAR_LENGTH(a.alias) THEN CONCAT_WS(\':\', a.id, a.alias) ELSE a.id END as slug, '
			. ' CASE WHEN CHAR_LENGTH(c.alias) THEN CONCAT_WS(\':\', c.id, c.alias) ELSE c.id END AS catslug, '
			. ' CONCAT_WS( " / ", '.$db->quote($section).', a.title ) AS section,'
			. ' "2" AS browsernav, c.id as catid, c.alias as catalias');
			$query->from('#__phocadownload AS a');
			$query->innerJoin('#__phocadownload_categories AS c ON c.id = a.catid');
			//$query->where('('.$where.')' . ' AND a.state in ('.implode(',',$state).') AND  a.published = 1 AND a.approved = 1 AND c.published = 1 AND c.approved = 1 AND  c.access IN ('.$groups.')');
			$query->where('('.$where.')' . ' AND  a.published = 1 AND a.approved = 1 AND c.published = 1 AND  c.access IN ('.$groups.')');
			//$query->group('a.id');
			$query->order($order);
				
			// Filter by language
			if ($app->isSite() && $app->getLanguageFilter()) {
				$tag = JFactory::getLanguage()->getTag();
				$query->where('a.language in (' . $db->quote($tag) . ',' . $db->quote('*') . ')');
				$query->where('c.language in (' . $db->quote($tag) . ',' . $db->quote('*') . ')');
			}

			$db->setQuery( $query, 0, $limit );
			$listFiles = $db->loadObjectList();
			

			if(isset($listFiles)) {
				foreach($listFiles as $key => $value) {
					// USER RIGHT - ACCESS - - - - - - - - - - - 
					$rightDisplay = 1;//default is set to 1 (all users can see the category)
			
					if (!empty($value)) {
						$rightDisplay = PhocaDownloadAccess::getUserRight('accessuserid', $value->accessuserid, $value->cataccess, $user->getAuthorisedViewLevels(), $user->get('id', 0), $display_access_category);
					}
					if ($rightDisplay == 0) {
						unset($listFiles[$key]);
					} else {
						if ($link_refer == 1) {
							$listFiles[$key]->href = JRoute::_(PhocaDownloadRoute::getFileRoute($value->id, $value->catid, $value->alias, $value->catalias));
						} else {
							$listFiles[$key]->href = JRoute::_(PhocaDownloadRoute::getCategoryRoute($value->catid, $value->catalias));
						}
						
					} 	
					// - - - - - - - - - - - - - - - - - - - - -
				}
			}
			$rows[] = $listFiles;
		}
		
		$results = array();
		if(count($rows)) {
			foreach($rows as $row) {
				$results = array_merge($results, (array) $row);
			}
		}
		
		return $results;
	}
}
