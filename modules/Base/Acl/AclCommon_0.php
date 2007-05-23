<?php
/**
 * Acl class.
 * 
 * This class provides functions for administrating the ACL.
 * 
 * @author Paul Bukowski <pbukowski@telaxus.com>
 * @copyright Copyright &copy; 2006, Telaxus LLC
 * @version 0.9
 * @package tcms-base-extra
 */
defined("_VALID_ACCESS") || die('Direct access forbidden');

/**
 * This class provides functions for administrating the ACL.
 * @package tcms-base-extra
 * @subpackage acl
 */
class Base_AclCommon {
	
	/**
	 * Gets array of acl groups. Don't use any parameters, it is recursive function.
	 * Array keys are groups ids, values are names with separators as hierarchy.
	 * 
	 * @param bool
	 * @param string
	 * @return array
	 */
	public static function get_groups($id=false, $separator='') {
		
		static $groups = array();
		
		if($id == false) $id = Acl::$gacl->get_root_group_id();
		
		if(!self::i_am_sa() && self::sa_group_id()==$id) return; 
		
		$arr = Acl::$gacl->get_group_data($id);
		$groups[$id] = $separator.$arr[3];
		
		$children = Acl::$gacl->get_group_children($id);
		foreach($children as $ch)
			self::get_groups($ch, $separator.'&nbsp;&nbsp;');
		
		return $groups;
	}
	
	/**
	 * Subscribe user to groups and unsubscribe from old groups.
	 * 
	 * @param string user login
	 * @param array array with id's of new groups
	 */
	public static function change_privileges($user, $groups_new) {
		
		$uid = self::get_acl_user_id($user);
		if($uid === false) {
			print(Base_LangCommon::ts('Base/Acl','invalid user'));
			return false;
		}
		
		$groups_old = self::get_user_groups($uid);
		
		//check access
		if(!self::i_am_sa()) {
			$merge = array_merge($groups_new, $groups_old);
			foreach($merge as $g)
				if($g==self::sa_group_id()) {
					print(Base_LangCommon::ts('Base/Acl','You cannot modify Super administrator group, because you are only Administrator!'));
					return false;
				}
		}
		
		$intersect = array_intersect($groups_new, $groups_old);
		
		foreach($groups_old as $g)
			if(!in_array($g, $intersect))
				Acl::$gacl->del_group_object($g, 'Users', $user);

		foreach($groups_new as $g)
			if(!in_array($g, $intersect))
				Acl::$gacl->add_group_object($g, 'Users', $user);
		
		return true;
	}
	
	/**
	 * Return if user calling this function is Super Administrator.
	 * 
	 * @return bool
	 */
	public static function i_am_sa($cache=false) {
		static $ret;
		if(!isset($ret) || !$cache) $ret = (Variable::get('anonymous_setup') || Acl::check('Administration','Main'));
		return $ret;
	}
	
	public static function i_am_admin($cache=false) {
		static $ret;
		if(!isset($ret) || !$cache) $ret = (Variable::get('anonymous_setup') || Acl::check('Administration','Modules'));
		return $ret;
	}

	public static function i_am_moderator($cache=false) {
		static $ret;
		if(!isset($ret) || !$cache) $ret = Acl::check('Data','Moderation');
		return $ret;
	}

	public static function i_am_user($cache=false) {
		static $ret;
		if(!isset($ret) || !$cache) $ret = Acl::check('Data','View');
		return $ret;
	}
	
	/**
	 * Return id of Super administrator group.
	 * 
	 * @return integer
	 */
	public static function sa_group_id() {
		return self::get_group_id('Super administrator');
	}
	
	/**
	 * Return id of group.
	 * 
	 * @param string group name
	 * @return integer
	 */
	public static function get_group_id($g) {
		static $ret;
		if(!isset($ret[$g])) {
			
			$ret[$g] = Acl::$gacl->get_group_id($g);
		}
		return $ret[$g];
	}
	
	/**
	 * Get user id assigned by phpgacl. This value doesn't equal user id from User module.
	 * 
	 * @param string
	 * @return integer
	 */
	public static function get_acl_user_id($user) {
		
		return Acl::$gacl->get_object_id('Users', $user, 'ARO');
	}
	
	/**
	 * Get names of groups assigned to user.
	 * 
	 * @param integer user acl id (use get_acl_user_id)
	 * @return mixed false if you are not super administrator and pointed user is super administrator, string with comma separated group names otherwise.
	 */
	public static function get_user_groups_names($uid) {
		
		$groups_arr = Acl::$gacl->get_object_groups($uid);
		$groups = array();
		foreach($groups_arr as $id) {
			$arr = Acl::$gacl->get_group_data($id);
			if(!self::i_am_sa() && $id == self::sa_group_id()) continue;
			$groups[] = $arr[3];
		}
		return implode($groups, '<br>');
	}
	
	public static function is_user_in_group($uid,$group) {
		
		$groups_arr = Acl::$gacl->get_object_groups($uid);
		$groups = array();
		foreach($groups_arr as $id) {
			$arr = Acl::$gacl->get_group_data($id);
			if($arr[3]==$group) return true;
		}
		return false;
	}
	
	/**
	 * Get groups assigned to user.
	 * 
	 * @param integer
	 * @return array
	 */
	public static function get_user_groups($uid) {
		
		return Acl::$gacl->get_object_groups($uid);
	}
	
	public static function add_user($username) {
		//check if user is in acl
		$aro_id = Acl::$gacl->get_object_id('Users', $username, 'ARO');
		//delete object and all refs, if exists
		if($aro_id) Acl::$gacl->del_object($aro_id, 'ARO', true);
		return Acl::$gacl->add_object('Users', $username, $username, 1, 0, 'ARO') &&
			Acl::$gacl->add_group_object(Acl::$gacl->get_group_id('User'), 'Users', $username);
	}
}

?>
