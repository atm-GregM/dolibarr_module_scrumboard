<?php
/* <one line to give the program's name and a brief idea of what it does.>
 * Copyright (C) 2013 ATM Consulting <support@atm-consulting.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *	\file		lib/scrumboard.lib.php
 *	\ingroup	scrumboard
 *	\brief		This file is an example module library
 *				Put some comments here
 */

function scrumboardAdminPrepareHead()
{
    global $langs, $conf;

    $langs->load("scrumboard@scrumboard");

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath("/scrumboard/admin/scrumboard_setup.php", 1);
    $head[$h][1] = $langs->trans("Settings");
    $head[$h][2] = 'settings';
    $h++;
    $head[$h][0] = dol_buildpath("/scrumboard/admin/about.php", 1);
    $head[$h][1] = $langs->trans("About");
    $head[$h][2] = 'about';
    $h++;

    // Show more tabs from modules
    // Entries must be declared in modules descriptor with line
    //$this->tabs = array(
    //	'entity:+tabname:Title:@scrumboard:/scrumboard/mypage.php?id=__ID__'
    //); // to add new tab
    //$this->tabs = array(
    //	'entity:-tabname:Title:@scrumboard:/scrumboard/mypage.php?id=__ID__'
    //); // to remove a tab
    complete_head_from_modules($conf, $langs, $object, $head, $h, 'scrumboard');

    return $head;
}

function scrum_getVelocity(&$db, $id_project) {
	global $conf;

	$t2week= strtotime('-2weeks');

	$projet=new Project($db);
	$projet->fetch($id_project);

	if($projet->date_start>$t2week) $t2week = $projet->date_start;

	$res=$db->query("SELECT SUM(tt.task_duration) as task_duration
	FROM ".MAIN_DB_PREFIX."projet_task_time tt LEFT JOIN ".MAIN_DB_PREFIX."projet_task t ON (tt.fk_task=t.rowid)
	WHERE tt.task_date>='".date('Y-m-d', $t2week)."' AND t.fk_projet=".$id_project);

	$velocity = 0;
	if($obj=$db->fetch_object($res)) {
		 $velocity = round($obj->task_duration / ((time() - $t2week) / 86400));
	}

	if($velocity==0)$velocity = (int)$conf->global->SCRUM_DEFAULT_VELOCITY * 3600;

	return $velocity;
}


function getAllTaskInStory($fk_project, $story) {
    global $db;

    // Sélectionne toutes les taches existantes liées à une story
    $sql = 'SELECT t.rowid';
    $sql .= ' FROM '.MAIN_DB_PREFIX.'projet_task AS t';
    $sql .= ' WHERE t.story_k = '.intval($story).' AND t.fk_projet = '.intval($fk_project);

    $resql = $db->query($sql);

    $TData = array();
    if($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $TData[] = $obj->rowid;
        }
    }


    return $TData;
}

/**
 * @param DoliDB $db
 * @param int    $id_project
 * @param string $status
 * @param int    $fk_user
 * @param int    $fk_soc
 * @param string $soc_type
 * @param array  $TDateFilters
 * @param array  $search_array_options
 * @param Task   $task
 * @param array  $extrafieldstask
 * @param string $label_filter
 * @param int    $country_filter
 * @param int    $state_filter
 * @return array|string
 */
function getSQLForTasks(
	&$db,
	$id_project,
	$status,
	$fk_user,
	$fk_soc,
	$soc_type,
	$TDateFilters,
	$search_array_options,
	$task,
	$extrafieldstask,
	$label_filter,
	$country_filter,
	$state_filter
) {
	global $conf, $hookmanager;

	dol_include_once('scrumboard/class/scrumboard.class.php');

	$sql = 'SELECT DISTINCT pt.rowid, pt.story_k, pt.scrum_status, pt.rang
			FROM '.MAIN_DB_PREFIX.'projet_task pt
			INNER JOIN '.MAIN_DB_PREFIX.'projet p ON (p.rowid = pt.fk_projet)';

	if (!empty($search_array_options)) $sql.= ' INNER JOIN '.MAIN_DB_PREFIX.'projet_task_extrafields ef ON (ef.fk_object = pt.rowid)';

	if(empty($id_project) && $status != 'unknownColumn')
	{
		$sql.= ' INNER JOIN ' . MAIN_DB_PREFIX . 'projet_storie ps ON (ps.fk_projet = pt.fk_projet AND ps.storie_order = pt.story_k)';
	}

	if (!empty($conf->global->SCRUM_FILTER_BY_USER_ENABLE) && $fk_user > 0)
	{
		$sql.= ' INNER JOIN '.MAIN_DB_PREFIX.'element_contact ec ON (ec.element_id = pt.rowid)';
		$sql.= ' INNER JOIN '.MAIN_DB_PREFIX.'c_type_contact tc ON (tc.rowid = ec.fk_c_type_contact)';
	}
	if ((!empty($country_filter) || !empty($state_filter)) && !empty($search_array_options))
	{
		$sql.= ' INNER JOIN '.MAIN_DB_PREFIX.'societe soc ON (ef.fk_etablissement = soc.rowid)';
	}

	if($status == 'unknownColumn') {
		$sql .= ' WHERE (scrum_status NOT IN (SELECT code FROM '.MAIN_DB_PREFIX.'c_scrum_columns WHERE active=1))';
	}
	else {
		$sql.= ' WHERE 1 ';

		if (!empty($status)) {
			$sql.= ' AND ((scrum_status IS NOT NULL AND scrum_status = "'.$db->escape($status).'")';
			if      ($status=='ideas')      $sql.= ' OR (scrum_status IS NULL AND (progress = 0 OR progress IS NULL) AND datee IS NULL)';
			else if ($status=='todo')       $sql.= ' OR (scrum_status IS NULL AND (progress = 0 OR progress IS NULL))';
			else if ($status=='inprogress') $sql.= ' OR (scrum_status IS NULL AND  progress > 0 AND progress < 100)';
			else if ($status=='finish')     $sql.= ' OR (scrum_status IS NULL AND  progress=100)';
			$sql .= ')';
		}
	}

	if($id_project > 0) $sql.= ' AND fk_projet='.$id_project;

	if (!empty($conf->global->SCRUM_FILTER_BY_USER_ENABLE) && $fk_user > 0)
	{
		$sql.= ' AND tc.element = \'project_task\' AND ec.fk_socpeople = '.$fk_user;
	}

	$parameters = array('id_project' => $id_project, 'fk_soc' => $fk_soc, 'soc_type' => $soc_type);
	$reshook = $hookmanager->executeHooks('scrumManageFk_socSQL', $parameters, $task, $action);
	if ($reshook > 0) $sql.=$hookmanager->resPrint;
	if (empty($reshook) && $fk_soc > 0)
	{
		if ($soc_type === 'onlycompany' || $soc_type === 'both')
		{
			$sql.= ' AND ';
			if ($soc_type === 'both') $sql.= ' ( ';
			$sql.= 'p.fk_soc = '.$fk_soc;
		}

		if ($soc_type === 'onlychildren' || $soc_type === 'both')
		{
			$resql = $db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'societe WHERE parent = '.$fk_soc);
			if ($resql)
			{
				$TSocId = array();
				while ($obj = $db->fetch_object($resql))
				{
					$TSocId[] = $obj->rowid;
				}

				if (!empty($TSocId))
				{
					if ($soc_type === 'both') $sql.= ' OR ';
					else $sql.= ' AND ';
					$sql.= 'p.fk_soc IN ('.implode(',', $TSocId).')';
				}
				else
				{
					$sql.= 'p.fk_soc = -1';
				}
			}
			else
			{
				dol_print_error($db);
			}

			if ($soc_type === 'both') $sql.= ' ) ';
		}
	}
	// date filter
	LIST ($start_date_after, $start_date_before, $end_date_after, $end_date_before) = $TDateFilters;

	// add error if date range boundaries are not in the right order (negative range)
	$startDateNegativeDateRange = !empty($start_date_before) && $start_date_after > $start_date_before;
	$endDateNegativeDateRange   = !empty($end_date_before)   && $end_date_after   > $end_date_before;
	if ($startDateNegativeDateRange || $endDateNegativeDateRange)
	{
		global $langs;
		return array(
			'error' => true,
			'message' => $langs->trans('FilterErrorNegativeDateRange')
		);
	}
	if (!empty($start_date_after))  $sql .= ' AND pt.dateo >= ' . "'" . $db->idate($start_date_after)  . "'";
	if (!empty($start_date_before)) $sql .= ' AND pt.dateo <= ' . "'" . $db->idate($start_date_before) . "'";
	if (!empty($end_date_after))    $sql .= ' AND pt.datee >= ' . "'" . $db->idate($end_date_after)    . "'";
	if (!empty($end_date_before))   $sql .= ' AND pt.datee <= ' . "'" . $db->idate($end_date_before)   . "'";

	// extrafields filters
	if (!empty($search_array_options))
	{
		$extrafields = &$extrafieldstask; // Compatibility for tpl
		$action = 'setSqlExtrafields';
		$parameters = array('sql' => &$sql, 'id_project' => $id_project, 'status' => $status, 'fk_user' => $fk_user, 'fk_soc' => $fk_soc, 'soc_type' => $soc_type, 'TDateFilters' => $TDateFilters, 'search_array_options' => $search_array_options, 'extrafieldstask' => $extrafieldstask, 'label_filter' => $label_filter, 'country_filter' => $country_filter, 'state_filter' => $state_filter);
		$reshook = $hookmanager->executeHooks('doTasks', $parameters, $task, $action); // Note that $action and $object may have been modified by some
		if ($reshook < 0) setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');

		if (empty($reshook))
		{
			// Add where from extra fields
			include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_sql.tpl.php';
		}
	}
	// filter on label
	if (!empty($label_filter))
	{
		$sql .= ' AND pt.label LIKE \'%' . $db->escape($label_filter) . '%\'';
	}
	// filter on state / country
	if (!empty($country_filter))
	{
		$sql .= ' AND soc.fk_pays = ' . $country_filter;
	}
	if (!empty($state_filter))
	{
		$sql .= ' AND soc.fk_departement = ' . $state_filter;
	}

	$sql.= ' ORDER BY pt.rang';

	return $sql;
}

	/**
	 * @param DoliDB $db
	 * @param int    $id_task
	 * @param array  $values
	 * @return array  Associative array with all the task details the scrumboard card needs
	 */
function getTaskDetailsForScrumboardCard(&$db, $id_task, $values=array()) {
	global $user, $langs,$conf;

	$task=new Task($db);
	if($id_task) $task->fetch($id_task);

	$sql = 'SELECT sourcetype, fk_source
		FROM ' . MAIN_DB_PREFIX . 'element_element
		WHERE targettype = "' . $task->element . '"
		AND fk_target = ' . intval($task->id);

	$resql = $db->query($sql);

	$obj = $db->fetch_object($resql);

	// Méthodes sur les commentaires ajoutées en standard depuis la 7.0
	if(! empty($conf->global->PROJECT_ALLOW_COMMENT_ON_TASK) && empty($task->comments) && method_exists($task, 'fetchComments')) $task->fetchComments();

	if(! empty($obj)) {
		$sourcetype = $obj->sourcetype;
		$fk_line = $obj->fk_source;

		if($sourcetype == 'orderline') $line = new OrderLine($db);
		else if($sourcetype == 'propaldet') $line = new PropaleLigne($db);

		if(! empty($line) && ! empty($fk_line)) $line->fetch($fk_line);

		if($sourcetype == 'orderline') {
			$task->origin = 'order';
			$task->origin_id = $line->fk_commande;
		}
		else if($sourcetype == 'propaldet') {
			$task->origin = 'propal';
			$task->origin_id = $line->fk_propal;
		}
	}

	if(!empty($values)){
		_set_values($task, $values);

		if($values['status']=='inprogress') {
			if($task->progress==0)$task->progress = 5;
			else if($task->progress==100)$task->progress = 95;
		}
		else if($values['status']=='finish') {
			$task->progress = 100;
		}
		else if($values['status']=='todo') {
			$task->progress = 0;
		}

		$task->status = $values['status'];
		$task->update($user);

		$db->query("UPDATE ".MAIN_DB_PREFIX.$task->table_element."
			SET story_k=".(int)$values['story_k']."
			,scrum_status='".$values['scrum_status']."'
		WHERE rowid=".$task->id);
	}

	// Méthodes sur les commentaires ajoutées en standard depuis la 7.0
	if(!empty($conf->global->PROJECT_ALLOW_COMMENT_ON_TASK) && method_exists($task, 'getNbComments')) {
		$task->nbcomment = $task->getNbComments();
	}

	$task->date_delivery = 0;
	if($task->date_end >0 && $task->planned_workload>0) {

		$velocity = scrum_getVelocity($db, $task->fk_project);
		$task->date_delivery = _get_delivery_date_with_velocity($db, $task, $velocity);

	}

//    $timespentoutputformat='all';
//    if (! empty($conf->global->PROJECT_TIMES_SPENT_FORMAT)) $timespentoutputformat=$conf->global->PROJECT_TIME_SPENT_FORMAT;
	$working_timespentoutputformat='all';
	if (! empty($conf->global->PROJECT_WORKING_TIMES_SPENT_FORMAT)) $working_timespentoutputformat=$conf->global->PROJECT_WORKING_TIMES_SPENT_FORMAT;

	$working_days_per_weeks=7;
	$dayInSecond = 86400;
	if (!empty($conf->global->PROJECT_WORKING_HOURS_PER_DAY))
	{
		$working_days_per_weeks=!empty($conf->global->PROJECT_WORKING_DAYS_PER_WEEKS) ? $conf->global->PROJECT_WORKING_DAYS_PER_WEEKS : 5;
		$working_hours_per_day=!empty($conf->global->PROJECT_WORKING_HOURS_PER_DAY) ? $conf->global->PROJECT_WORKING_HOURS_PER_DAY : 7;
		$working_hours_per_day_in_seconds = 3600 * $working_hours_per_day;
		$dayInSecond = $working_hours_per_day_in_seconds;
	}
	elseif($conf->global->SCRUM_DEFAULT_VELOCITY){
		$dayInSecond = 60*60*$conf->global->SCRUM_DEFAULT_VELOCITY;
	}

	$task->aff_time = convertSecondToTime($task->duration_effective,$working_timespentoutputformat,$dayInSecond, $working_days_per_weeks);
	$task->aff_planned_workload = convertSecondToTime($task->planned_workload,$working_timespentoutputformat,$dayInSecond, $working_days_per_weeks);

	$task->long_description.='';
	if(!empty($conf->global->SCRUM_SHOW_DATES_IN_DESCRIPTION)) {
		if($task->date_start>0) $task->long_description .= $langs->trans('TaskDateStart').' : '.dol_print_date($task->date_start).'<br />';
		if($task->date_end>0) $task->long_description .= $langs->trans('TaskDateEnd').' : '.dol_print_date($task->date_end).'<br />';
		if($task->date_delivery>0 && $task->date_delivery>$task->date_end) $task->long_description .= $langs->trans('TaskDateShouldDelivery').' : '.dol_print_date($task->date_delivery).'<br />';
	}
	$task->long_description.=nl2br($task->description);

	if (!empty($conf->global->SCRUM_SHOW_LINKED_CONTACT)) _getTContact($task);

	$task->formatted_date_start_end = '';
	if (!empty($conf->global->SCRUM_SHOW_DATES)) $task->formatted_date_start_end = dol_print_date($task->date_start, 'day') . ' - ' . dol_print_date($task->date_end, 'day');

	return _as_array($task);
}
