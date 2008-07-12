<?php
/**
 * RecordBrowserCommon class.
 *
 * @author Arkadiusz Bisaga <abisaga@telaxus.com>
 * @copyright Copyright &copy; 2006, Telaxus LLC
 * @version 0.99
 * @package tcms-extra
 */

defined("_VALID_ACCESS") || die('Direct access forbidden');

class Utils_RecordBrowserCommon extends ModuleCommon {
	private static $table_rows = array();
	private static $del_or_a = '';
	private static $hash = array();
	public static $cols_order = array();

	public static function format_long_text_array($tab,$records){
		self::init($tab);
		foreach(self::$table_rows as $field => $args) {
			if ($args['type']!='long text') continue;
			foreach ($records as $k=>$v) {
				$records[$k][$args['id']] = str_replace("\n",'<br>',htmlspecialchars($v[$args['id']]));
			}
		}
		return $records;
	}
	public static function format_long_text($tab,$record){
		self::init($tab);
		foreach(self::$table_rows as $field => $args) {
			if ($args['type']!='long text') continue;
			$record[$args['id']] = str_replace("\n",'<br>',htmlspecialchars($record[$args['id']]));
		}
		return $record;
	}
	
	public static function user_settings(){
		$ret = DB::Execute('SELECT tab, caption, icon, recent, favorites, full_history FROM recordbrowser_table_properties');
		$settings = array(); 
		while ($row = $ret->FetchRow()) {
			if (!$row['favorites'] && !$row['recent']) continue;
			if (!self::get_access($row['tab'],'browse')) continue;
			$options = array('all'=>'All');
			if ($row['favorites']) $options['favorites'] = 'Favorites';
			if ($row['recent']) $options['recent'] = 'Recent';
			$settings[] = array('name'=>$row['tab'].'_default_view','label'=>$row['caption'].' - default view','type'=>'select','values'=>$options,'default'=>'all');
		}
		return array('Default data view'=>$settings);
	}
	public static function check_table_name($tab, $flush=false){
		static $tables = null;
		if ($tables===null || $flush) {
			$r = DB::GetAll('SELECT tab FROM recordbrowser_table_properties');
			$tables = array();
			foreach($r as $v)
				$tables[$v['tab']] = true;
		}
		if (($tab!=null && !isset($tables[$tab])) && !$flush) trigger_error('RecordBrowser critical failure, terminating. (Requested '.$tab.', available '.print_r($tables, true).')', E_USER_ERROR);
	}
	public static function get_value($tab, $id, $field) {
		self::check_table_name($tab);
		return DB::GetOne('SELECT value FROM '.$tab.'_data WHERE field=%s AND '.$tab.'_id=%d', array($field, $id));
	}
	public static function admin_caption() {
		return 'Records Sets';
	}
	public static function init($tab, $admin=false) {
		static $cache = array();
		if (!isset(self::$cols_order[$tab])) self::$cols_order[$tab] = array();
		if (isset($cache[$tab.'__'.$admin.'__'.md5(serialize(self::$cols_order[$tab]))])) {
			self::$hash = $cache[$tab.'__'.$admin.'__'.md5(serialize(self::$cols_order[$tab]))]['hash'];
			return self::$table_rows = $cache[$tab.'__'.$admin.'__'.md5(serialize(self::$cols_order[$tab]))]['rows'];
		}
		self::$table_rows = array();
		self::check_table_name($tab);
		$ret = DB::Execute('SELECT * FROM '.$tab.'_field'.($admin?'':' WHERE active=1 AND type!=\'page_split\'').' ORDER BY position');
		self::$hash = array();
		while($row = $ret->FetchRow()) {
			if ($row['field']=='id') continue;
			self::$table_rows[$row['field']] =
				array(	'name'=>$row['field'],
						'id'=>strtolower(str_replace(' ','_',$row['field'])),
						'type'=>$row['type'],
						'visible'=>$row['visible'],
						'required'=>$row['required'],
						'extra'=>$row['extra'],
						'active'=>$row['active'],
						'position'=>$row['position'],
						'filter'=>$row['filter'],
						'style'=>$row['style'],
						'param'=>$row['param']);
			self::$hash[self::$table_rows[$row['field']]['id']] = $row['field'];
		}
		if (!empty(self::$cols_order[$tab])) {
			$rows = array();
			foreach (self::$cols_order[$tab] as $v) {
				$rows[self::$hash[$v]] = self::$table_rows[self::$hash[$v]];
				unset(self::$table_rows[self::$hash[$v]]);
			}
			foreach(self::$table_rows as $k=>$v)
				$rows[$k] = $v;
			self::$table_rows = $rows;
		}
		$cache[$tab.'__'.$admin.'__'.md5(serialize(self::$cols_order[$tab]))] = array('rows'=>self::$table_rows,'hash'=>self::$hash);
		return self::$table_rows;
	}

	public static function install_new_recordset($tab = null, $fields) {
		if (!$tab) return false;
		if (!preg_match('/^[a-zA-Z_]+$/',$tab)) trigger_error('Invalid table name ('.$tab.') given to install_new_recordset.',E_USER_ERROR);
		DB::Execute('INSERT INTO recordbrowser_table_properties (tab) VALUES (%s)', array($tab));
		self::check_table_name(null, true);
		DB::CreateTable($tab,
					'id I AUTO KEY,'.
					'created_on T NOT NULL,'.
					'created_by I NOT NULL,'.
					'private I4 DEFAULT 0,'.
					'active I1 NOT NULL DEFAULT 1',
					array('constraints'=>', FOREIGN KEY (created_by) REFERENCES user_login(id)'));
		DB::CreateTable($tab.'_data',
					$tab.'_id I,'.
					'field C(32) NOT NULL,'.
					'value C(255) NOT NULL',
					array('constraints'=>', FOREIGN KEY ('.$tab.'_id) REFERENCES '.$tab.'(id)'));
		DB::CreateTable($tab.'_field',
					'field C(32) UNIQUE NOT NULL,'.
					'type C(32),'.
					'extra I1 DEFAULT 1,'.
					'visible I1 DEFAULT 1,'.
					'required I1 DEFAULT 1,'.
					'active I1 DEFAULT 1,'.
					'position I,'.
					'filter I1 DEFAULT 0,'.
					'param C(255),'.
					'style C(64)',
					array('constraints'=>''));
		DB::CreateTable($tab.'_edit_history',
					'id I AUTO KEY,'.
					$tab.'_id I NOT NULL,'.
					'edited_on T NOT NULL,'.
					'edited_by I NOT NULL',
					array('constraints'=>', FOREIGN KEY (edited_by) REFERENCES user_login(id)'.
											', FOREIGN KEY ('.$tab.'_id) REFERENCES '.$tab.'(id)'));
		DB::CreateTable($tab.'_edit_history_data',
					'edit_id I,'.
					'field C(32),'.
					'old_value C(255)',
					array('constraints'=>', FOREIGN KEY (edit_id) REFERENCES '.$tab.'_edit_history(id)'));
		DB::CreateTable($tab.'_favorite',
					$tab.'_id I,'.
					'user_id I',
					array('constraints'=>', FOREIGN KEY (user_id) REFERENCES user_login(id)'.
										', FOREIGN KEY ('.$tab.'_id) REFERENCES '.$tab.'(id)'));
		DB::CreateTable($tab.'_recent',
					$tab.'_id I,'.
					'user_id I,'.
					'visited_on T',
					array('constraints'=>', FOREIGN KEY (user_id) REFERENCES user_login(id)'.
										', FOREIGN KEY ('.$tab.'_id) REFERENCES '.$tab.'(id)'));
		DB::CreateTable($tab.'_callback',
					'field C(32),'.
					'module C(64),'.
					'func C(128),'.
					'freezed I1',
					array('constraints'=>''));
		DB::CreateTable($tab.'_require',
					'field C(32),'.
					'req_field C(64),'.
					'value C(128)',
					array('constraints'=>''));
		DB::Execute('INSERT INTO '.$tab.'_field(field, type, extra, visible, position) VALUES(\'id\', \'foreign index\', 0, 0, 1)');
		DB::Execute('INSERT INTO '.$tab.'_field(field, type, extra, position) VALUES(\'General\', \'page_split\', 0, 2)');
		DB::Execute('INSERT INTO '.$tab.'_field(field, type, extra, position) VALUES(\'Details\', \'page_split\', 0, 3)');
		$datatypes = array();
		$ret = DB::Execute('SELECT * FROM recordbrowser_datatype');
		while ($row = $ret->FetchRow())
			$datatypes[$row['type']] = array($row['module'], $row['func']);
		foreach ($fields as $v) {
			if (!isset($v['param'])) $v['param'] = '';
			if (!isset($v['style'])) { 
				if (in_array($v['type'], array('timestamp','currency','integer')))
					$v['style'] = $v['type'];
				else 
					$v['style'] = '';
			}
			if (!isset($v['extra'])) $v['extra'] = true;
			if (!isset($v['visible'])) $v['visible'] = false;
			if (!isset($v['required'])) $v['required'] = false;
			if (!isset($v['filter'])) $v['filter'] = false;
			if (isset($datatypes[$v['type']])) $v = call_user_func($datatypes[$v['type']], $v);
			Utils_RecordBrowserCommon::new_record_field($tab, $v['name'], $v['type'], $v['visible'], $v['required'], $v['param'], $v['style'], $v['extra'], $v['filter']);
			if (isset($v['display_callback'])) self::set_display_method($tab, $v['name'], $v['display_callback'][0], $v['display_callback'][1]);
			if (isset($v['QFfield_callback'])) self::set_QFfield_method($tab, $v['name'], $v['QFfield_callback'][0], $v['QFfield_callback'][1]);
			if (isset($v['requires']))
				foreach($v['requires'] as $k=>$w) {
					if (!is_array($w)) $w = array($w);
					foreach($w as $c)
						self::field_requires($tab, $v['name'], $k, $c);
				}
		}
		return true;
	}
	public static function field_requires($tab = null, $field, $req_field, $val) {
		if (!$tab) return false;
		self::check_table_name($tab);
		DB::Execute('INSERT INTO '.$tab.'_require (field, req_field, value) VALUES(%s, %s, %s)', array($field, $req_field, $val));
	}
	public static function set_display_method($tab = null, $field, $module, $func) {
		if (!$tab) return false;
		self::check_table_name($tab);
		DB::Execute('INSERT INTO '.$tab.'_callback (field, module, func, freezed) VALUES(%s, %s, %s, 1)', array($field, $module, $func));
	}
	public static function set_QFfield_method($tab = null, $field, $module, $func) {
		if (!$tab) return false;
		self::check_table_name($tab);
		DB::Execute('INSERT INTO '.$tab.'_callback (field, module, func, freezed) VALUES(%s, %s, %s, 0)', array($field, $module, $func));
	}

	public static function uninstall_recordset($tab = null) {
		if (!$tab) return false;
		self::check_table_name($tab);
		DB::DropTable($tab.'_callback');
		DB::DropTable($tab.'_require');
		DB::DropTable($tab.'_recent');
		DB::DropTable($tab.'_favorite');
		DB::DropTable($tab.'_edit_history_data');
		DB::DropTable($tab.'_edit_history');
		DB::DropTable($tab.'_field');
		DB::DropTable($tab.'_data');
		DB::DropTable($tab);
		DB::Execute('DELETE FROM recordbrowser_table_properties WHERE tab=%s', array($tab));
		return true;
	}

	public static function delete_record_field($tab, $field){
		self::check_table_name($tab);
		DB::Execute('DELETE FROM '.$tab.'_field WHERE field=%s', array($field));
	}
	public static function new_record_field($tab, $field, $type, $visible, $required, $param='', $style='', $extra = true, $filter = false){
		self::check_table_name($tab);
		$exists = DB::GetOne('SELECT field FROM '.$tab.'_field WHERE field=%s', array($field));
		if ($exists) return;
		if ($extra) {
			$pos = DB::GetOne('SELECT MAX(position) FROM '.$tab.'_field')+1;
		} else {
			DB::StartTrans();
			$pos = DB::GetOne('SELECT position FROM '.$tab.'_field WHERE field=\'Details\'');
			DB::Execute('UPDATE '.$tab.'_field SET position = position+1 WHERE position>=%d', array($pos));
			DB::CompleteTrans();
		}
		if (is_array($param)) {
			if ($type=='commondata') {
				$tmp = '';
				foreach ($param as $v) {
					$tmp .= ($tmp==''?'':'::').$v;
				}
				$param = $tmp;
			} else {
				$tmp = '';
				foreach ($param as $k=>$v) $tmp .= $k.'::'.$v;
				$param = $tmp;
			}
		}
		DB::Execute('INSERT INTO '.$tab.'_field(field, type, visible, param, style, position, extra, required, filter) VALUES(%s, %s, %d, %s, %s, %d, %d, %d, %d)', array($field, $type, $visible?1:0, $param, $style, $pos, $extra?1:0, $required?1:0, $filter?1:0));
	}
	public static function new_addon($tab, $module, $func, $label) {
		$module = str_replace('/','_',$module);
		self::delete_addon($tab, $module, $func);
		DB::Execute('INSERT INTO recordbrowser_addon (tab, module, func, label) VALUES (%s, %s, %s, %s)', array($tab, $module, $func, $label));
	}
	public static function delete_addon($tab, $module, $func) {
		$module = str_replace('/','_',$module);
		DB::Execute('DELETE FROM recordbrowser_addon WHERE tab=%s AND module=%s AND func=%s', array($tab, $module, $func));
	}
	public static function register_datatype($type, $module, $func) {
		DB::Execute('INSERT INTO recordbrowser_datatype (type, module, func) VALUES (%s, %s, %s)', array($type, $module, $func));
	}
	public static function unregister_datatype($type) {
		DB::Execute('DELETE FROM recordbrowser_datatype WHERE type=%s', array($type));
	}
	public static function new_filter($tab, $col_name) {
		self::check_table_name($tab);
		DB::Execute('UPDATE '.$tab.'_field SET filter=1 WHERE field=%s', array($col_name));
	}
	public static function delete_filter($tab, $col_name) {
		self::check_table_name($tab);
		DB::Execute('UPDATE '.$tab.'_field SET filter=0 WHERE field=%s', array($col_name));
	}
	public static function set_processing_method($tab, $method) {
		DB::Execute('UPDATE recordbrowser_table_properties SET data_process_method=%s WHERE tab=%s', array($method[0].'::'.$method[1], $tab));
	}
	public static function set_quickjump($tab, $col_name) {
		DB::Execute('UPDATE recordbrowser_table_properties SET quickjump=%s WHERE tab=%s', array($col_name, $tab));
	}
	public static function set_tpl($tab, $filename) {
		DB::Execute('UPDATE recordbrowser_table_properties SET tpl=%s WHERE tab=%s', array($filename, $tab));
	}
	public static function set_favorites($tab, $value) {
		DB::Execute('UPDATE recordbrowser_table_properties SET favorites=%d WHERE tab=%s', array($value?1:0, $tab));
	}
	public static function set_recent($tab, $value) {
		DB::Execute('UPDATE recordbrowser_table_properties SET recent=%d WHERE tab=%s', array($value, $tab));
	}
	public static function set_full_history($tab, $value) {
		DB::Execute('UPDATE recordbrowser_table_properties SET full_history=%d WHERE tab=%s', array($value?1:0, $tab));
	}
	public static function set_caption($tab, $value) {
		DB::Execute('UPDATE recordbrowser_table_properties SET caption=%s WHERE tab=%s', array($value, $tab));
	}
	public static function set_icon($tab, $value) {
		DB::Execute('UPDATE recordbrowser_table_properties SET icon=%s WHERE tab=%s', array($value, $tab));
	}
	public static function set_access_callback($tab, $module, $func){
		DB::Execute('UPDATE recordbrowser_table_properties SET access_callback=%s WHERE tab=%s', array($module.'::'.$func, $tab));
	}
	public static function set_record_properties( $tab, $id, $info = array()) {
		self::check_table_name($tab);
		foreach ($info as $k=>$v)
			switch ($k) {
				case 'created_on': 	DB::Execute('UPDATE '.$tab.' SET created_on=%T WHERE id=%d', array($v, $id));
									break;
				case 'created_by': 	DB::Execute('UPDATE '.$tab.' SET created_by=%d WHERE id=%d', array($v, $id));
									break;
			}
	}
	public static function new_record( $tab = null, $values = array()) {
		if (!$tab) return false;
		self::init($tab);
		DB::StartTrans();
		DB::Execute('INSERT INTO '.$tab.' (created_on, created_by, active) VALUES (%T, %d, %d)',array(date('Y-m-d G:i:s'), Acl::get_user(), 1));
		$id = DB::Insert_ID($tab, 'id');
		self::add_recent_entry($tab, Acl::get_user(), $id);
		foreach(self::$table_rows as $field => $args) {
			if (!isset($values[$args['id']]) || $values[$args['id']]=='') continue;
			if (!is_array($values[$args['id']]))
				DB::Execute('INSERT INTO '.$tab.'_data ('.$tab.'_id, field, value) VALUES (%d, %s, %s)',array($id, $field, $values[$args['id']]));
			else
				foreach($values[$args['id']] as $v)
					DB::Execute('INSERT INTO '.$tab.'_data ('.$tab.'_id, field, value) VALUES (%d, %s, %s)',array($id, $field, $v));
		}
		DB::CompleteTrans();
		return $id;
	}
	public static function update_record($tab,$id,$values,$all_fields = false, $date = null) {
		DB::StartTrans();
		self::init($tab);
		$record = self::get_record($tab, $id);
		$access = self::get_access($tab, 'fields', $record);
		$diff = array();
		foreach(self::$table_rows as $field => $args){
			if ($access[$args['id']]=='hide' || $access[$args['id']]=='read-only') continue;
			if ($args['id']=='id') continue;
			if (!isset($values[$args['id']])) if ($all_fields) $values[$args['id']] = ''; else continue;
			if ($record[$args['id']]!=$values[$args['id']]) {
				DB::StartTrans();
				$val = DB::GetOne('SELECT value FROM '.$tab.'_data WHERE '.$tab.'_id=%d AND field=%s',array($id, $field));
				if ($val!==false) DB::Execute('DELETE FROM '.$tab.'_data WHERE '.$tab.'_id=%d AND field=%s',array($id, $field));
				if ($values[$args['id']] !== '') {
					if (!is_array($values[$args['id']])) $values[$args['id']] = array($values[$args['id']]);
					foreach ($values[$args['id']] as $v) 
						DB::Execute('INSERT INTO '.$tab.'_data(value, '.$tab.'_id, field) VALUES (%s, %d, %s)',array($v, $id, $field));
				}
				DB::CompleteTrans();
				$diff[$args['id']] = $record[$args['id']];
			}
		}
		if (!empty($diff)) {
			DB::Execute('INSERT INTO '.$tab.'_edit_history(edited_on, edited_by, '.$tab.'_id) VALUES (%T,%d,%d)', array((($date==null)?date('Y-m-d G:i:s'):$date), Acl::get_user(), $id));
			$edit_id = DB::Insert_ID(''.$tab.'_edit_history','id');
			foreach($diff as $k=>$v) {
				if (!is_array($v)) $v = array($v);
				foreach($v as $c)  
					DB::Execute('INSERT INTO '.$tab.'_edit_history_data(edit_id, field, old_value) VALUES (%d,%s,%s)', array($edit_id, $k, $c));
			}
		}
		DB::CompleteTrans();
	}
	public static function add_recent_entry($tab, $user_id ,$id){
		self::check_table_name($tab);
		DB::StartTrans();
		static $rec_size;
		if (!isset($rec_size)) $rec_size = DB::GetOne('SELECT recent FROM recordbrowser_table_properties WHERE tab=%s', array($tab));
		DB::Execute('DELETE FROM '.$tab.'_recent WHERE user_id = %d AND '.$tab.'_id = %d',
					array($user_id,
					$id));
		$ret = DB::SelectLimit('SELECT visited_on FROM '.$tab.'_recent WHERE user_id = %d ORDER BY visited_on DESC',
					$rec_size-1,
					-1,
					array($user_id));
		while($row_temp = $ret->FetchRow()) $row = $row_temp;
		if (isset($row)) {
			DB::Execute('DELETE FROM '.$tab.'_recent WHERE user_id = %d AND visited_on < %T',
						array($user_id,
						$row['visited_on']));
		}
		DB::Execute('INSERT INTO '.$tab.'_recent VALUES (%d, %d, %T)',
					array($id,
					$user_id,
					date('Y-m-d H:i:s')));
		DB::CompleteTrans();
	}
	public static function build_query( $tab = null, $crits = null, $admin = false, $order = array()) {
		$key=$tab.'__'.serialize($crits).'__'.$admin.'__'.serialize($order);
		static $cache = array();
		self::init($tab, $admin);
		if (isset($cache[$key])) return $cache[$key];
		if (!$tab) return false;
		$having = '';
		$fields = '';
		$where = '';
		$final_tab = $tab.' AS r';
		$vals = array();
		if (!$crits) $crits = array();
		$access = self::get_access($tab, 'view');
		if ($access===false) return array();
		elseif ($access!==true && is_array($access))
			$crits = array_merge($crits, $access);
		$iter = 0;
		$hash = array();
		foreach (self::$table_rows as $field=>$args)
			$hash[$args['id']] = $field;
		foreach($order as $k=>$v) {
			if (!is_string($k)) break;
 			if ($k[0]==':') $order[] = array('column'=>$k, 'order'=>$k, 'direction'=>$v);
 			else $order[] = array('column'=>$hash[$k], 'order'=>$hash[$k], 'direction'=>$v);
			unset($order[$k]);
		}
		$old_crits = $crits;
		$crits = array();
		foreach($old_crits as $k=>$v) {
			$tk = trim($k, '"!|(<=>');
			if (isset($hash[$tk])) $crits[str_replace($tk, $hash[$tk], $k)] = $v;
			else $crits[$k] = $v;
		}
		
		$or_started = false;
		foreach($crits as $k=>$v){
			$negative = $noquotes = $or_start = $or = false;
			$operator = 'LIKE';
			while (($k[0]<'a' || $k[0]>'z') && ($k[0]<'A' || $k[0]>'Z') && $k[0]!=':') {
				if ($k[0]=='!') $negative = true;
				if ($k[0]=='"') $noquotes = true;
				if ($k[0]=='(') $or_start = true;
				if ($k[0]=='|') $or = true;
				if ($k[0]=='<') $operator = '<';
				if ($k[0]=='>') $operator = '>';
				if ($k[1]=='=' && $operator!='LIKE') {
					$operator .= '=';
					$k = substr($k, 2);
				} else $k = substr($k, 1);
				if (!isset($k[0])) trigger_error('Invalid criteria in build query: missing word.', E_USER_ERROR);
			}
			$or |= $or_start;
			if ($or) {
				if ($or_start && $or_started) {
					$having .= ')';
					$or_started = false;
				}
				if (!$or_started) $having .= ' AND (';
				else $having .= ' OR ';
				$or_started = true;
			} else {
				if ($or_started) $having .= ')';
				$or_started = false;
				$having .= ' AND ';
			}
			if ($k[0]==':') {
				switch ($k) {
					case ':Fav'	: $having .= ' (SELECT COUNT(*) FROM '.$tab.'_favorite WHERE '.$tab.'_id=r.id AND user_id=%d)!=0'; $vals[]=Acl::get_user(); break;
					case ':Recent'	: $having .= ' (SELECT COUNT(*) FROM '.$tab.'_recent WHERE '.$tab.'_id=r.id AND user_id=%d)!=0'; $vals[]=Acl::get_user(); break;
					case ':Created_on'	: 
							$inj = '';
							if(is_array($v))
								$inj = $v[0].DB::qstr($v[1]);
							elseif(is_string($v))
								$inj = $v;
							if($inj)
								$having .= ' created_on '.$inj; 
							break;
					case ':Created_by'	: 
							$having .= ' created_by = '.$v; 
							break;
					case ':Edited_on'	: 
							$inj = '';
							if(is_array($v))
								$inj = $v[0].DB::qstr($v[1]);
							elseif(is_string($v))
								$inj = $v;
							if($inj)
								$having .= ' (((SELECT MAX(edited_on) FROM '.$tab.'_edit_history WHERE '.$tab.'_id=r.id) '.$inj.') OR'.
										'((SELECT MAX(edited_on) FROM '.$tab.'_edit_history WHERE '.$tab.'_id=r.id) IS NULL AND created_on '.$inj.'))'; 
							break;
					default		: 
						if (substr($k,0,4)==':Ref')	{
							$params = explode(':', $k);
							$ref = $params[2];
							$param = explode(';', self::$table_rows[$ref]['param']);
							$param = explode('::',$param[0]);
							
							if (!isset($param[1])) $cols = $param[0];
							else {
								$tab2 = $param[0];
								$cols2 = $param[1];
							}
							if ($params[1]=='RefCD' || $tab2=='__COMMON__') {
								$ret = DB::Execute('SELECT cd1.akey AS id FROM utils_commondata_tree AS cd1 LEFT JOIN utils_commondata_tree AS cd2 ON cd1.parent_id=cd2.id WHERE cd1.value LIKE '.implode(' OR cd1.value LIKE ',$v).' AND cd2.akey='.DB::qstr($cols2));
								$allowed_cd = array();
								while ($row = $ret->FetchRow()) $allowed_cd[] = DB::qstr($row['id']);
								if (empty($allowed_cd)) {
									$having .= $negative?'1':'0';
									break;
								}
								$fields .= ', concat( \'::\', group_concat( rd'.$iter.'.value ORDER BY rd'.$iter.'.value SEPARATOR \'::\' ) , \'::\' ) AS val'.$iter;
								$final_tab = '('.$final_tab.') LEFT JOIN '.$tab.'_data AS rd'.$iter.' ON r.id=rd'.$iter.'.'.$tab.'_id AND rd'.$iter.'.field="'.$ref.'"';
								$having .= '(val'.$iter.' LIKE concat(\'%\','.implode(',\'%\') OR val'.$iter.' LIKE concat(\'%\',',$allowed_cd).',\'%\'))';
								$iter++;
								break;
							}
							$cols2 = explode('|', $cols2);
							foreach($cols2 as $j=>$w) $cols2[$j] = DB::qstr($w); 
							$cols2 = implode(' OR field=', $cols2);

							$fields .= ', concat( \'::\', (SELECT group_concat(rdt'.$iter.'.value SEPARATOR \'::\' ) FROM '.$tab2.'_data AS rdt'.$iter.' WHERE (rdt'.$iter.'.field='.$cols2.') AND rdt'.$iter.'.'.$tab2.'_id=rd'.$iter.'.value) , \'::\' ) AS val'.$iter;
							$final_tab = '('.$final_tab.') LEFT JOIN '.$tab.'_data AS rd'.$iter.' ON r.id=rd'.$iter.'.'.$tab.'_id AND rd'.$iter.'.field="'.$ref.'"';

							if (!is_array($v)) $v = array($v);
							if ($negative) $having .= '(';
							$having .= '('.($negative?'true':'false');
							foreach($v as $w) {
								if ($w==='') $having .= ' '.($negative?'AND':'OR').' val'.$iter.' IS '.($negative?'NOT ':'').'NULL';
								else {
									if (!$noquotes) $w = DB::qstr($w);
									$having .= ' '.($negative?'AND':'OR').' val'.$iter.' '.($negative?'NOT ':'').$operator.' '.DB::Concat(DB::qstr('%::'),$w,DB::qstr('::%'));
								}
							}
							$having .= ')';
							if ($negative) $having .= ' OR val'.$iter.' IS NULL)';

							$iter++;
						} else trigger_error('Unknow paramter given to get_records criteria: '.$k, E_USER_ERROR);
				}
			} else {
				if ($k == 'id') {
					if (!is_array($v)) $v = array($v);
					$having .= '('.($negative?'true':'false');
					foreach($v as $w) {
						if (!$noquotes) $w = DB::qstr($w);
						$having .= ' '.($negative?'AND':'OR').' id '.($negative?'NOT ':'').$operator.' '.$w;
					}
					$having .= ')';
				} else {
					if ($operator=='LIKE') $fields .= ', concat( \'::\', group_concat( rd'.$iter.'.value ORDER BY rd'.$iter.'.value SEPARATOR \'::\' ) , \'::\' ) AS val'.$iter;
					else $fields .= ', MAX(rd'.$iter.'.value) AS val'.$iter;
					$final_tab = '('.$final_tab.') LEFT JOIN '.$tab.'_data AS rd'.$iter.' ON r.id=rd'.$iter.'.'.$tab.'_id AND rd'.$iter.'.field="'.$k.'"';
					if (!is_array($v)) $v = array($v);
					if ($negative) $having .= '(';
					$having .= '('.($negative?'true':'false');
					foreach($v as $w) {
						if ($w==='') $having .= ' '.($negative?'AND':'OR').' val'.$iter.' IS '.($negative?'NOT ':'').'NULL';
						else {
							if (!$noquotes) $w = DB::qstr($w);
							$having .= ' '.($negative?'AND':'OR').' val'.$iter.' '.($negative?'NOT ':'').$operator.' '.($operator=='LIKE'?DB::Concat(DB::qstr('%::'),$w,DB::qstr('::%')):$w);
						}
					}
					$having .= ')';
					if ($negative) $having .= ' OR val'.$iter.' IS NULL)';
					$iter++;
				}
			}
		}
		if ($or_started) $having .= ')';
		$orderby = '';
		foreach($order as $v){
			if ($orderby=='') $orderby = ' ORDER BY';
			else $orderby .= ', ';
			if ($v['order'][0]==':') {
				switch ($v['order']) {
					case ':Fav'	: 
						$fields .= ', (SELECT COUNT(*) FROM '.$tab.'_favorite WHERE '.$tab.'_id=r.id AND user_id=%d) AS _fav_order';
						$orderby .= ' _fav_order '.$v['direction'];
						$vals[]=Acl::get_user();
						break;
					case ':Visited_on'	: 
						$fields .= ', (SELECT visited_on FROM '.$tab.'_recent WHERE '.$tab.'_id=r.id AND user_id=%d) AS _rec_order';
						$orderby .= ' _rec_order '.$v['direction'];
						$vals[]=Acl::get_user();
						break;
					case ':Edited_on'	: 
						$fields .= ', (CASE WHEN (SELECT MAX(edited_on) FROM '.$tab.'_edit_history WHERE '.$tab.'_id=r.id) THEN (SELECT MAX(edited_on) FROM '.$tab.'_edit_history WHERE '.$tab.'_id=r.id) ELSE created_on END) AS _edited_on';
						$orderby .= ' _edited_on '.$v['direction'];
						break;
					default		: trigger_error('Unknow paramter given to get_records criteria: '.$k, E_USER_ERROR);
				}
			} else {
				$param = explode(';', self::$table_rows[$v['order']]['param']);
				$param = explode('::',$param[0]);
				if (isset($param[1]) && $param[1]!='') {
					if (self::$table_rows[$v['order']]['type']!='commondata') {
						if (!isset($param[1])) $cols = $param[0];
						else {
							$tab2 = $param[0];
							$cols2 = $param[1];
						}
						if ($tab2!='__COMMON__') {
							$cols2 = explode('|', $cols2);
							foreach($cols2 as $j=>$w) $cols2[$j] = DB::qstr($w); 
							$cols2 = implode(' OR field=', $cols2);
		
							$fields .= ', concat( \'::\', (SELECT group_concat(rdt'.$iter.'.value SEPARATOR \'::\' ) FROM '.$tab2.'_data AS rdt'.$iter.' WHERE (rdt'.$iter.'.field='.$cols2.') AND rdt'.$iter.'.'.$tab2.'_id=rd'.$iter.'.value) , \'::\' ) AS val'.$iter;
							$final_tab = '('.$final_tab.') LEFT JOIN '.$tab.'_data AS rd'.$iter.' ON r.id=rd'.$iter.'.'.$tab.'_id AND rd'.$iter.'.field="'.$v['order'].'"';
							$orderby .= ' val'.$iter.' '.$v['direction'];
							$iter++;
							continue;
						}
					}
				} 
				$fields .= ', concat( \'::\', group_concat( rd'.$iter.'.value ORDER BY rd'.$iter.'.value SEPARATOR \'::\' ) , \'::\' ) AS val'.$iter;
				$final_tab = '('.$final_tab.') LEFT JOIN '.$tab.'_data AS rd'.$iter.' ON r.id=rd'.$iter.'.'.$tab.'_id AND rd'.$iter.'.field="'.$v['order'].'"';
				$orderby .= ' val'.$iter.' '.$v['direction'];
				$iter++;
			}
		}
		$ret = array('sql'=>'SELECT id, active, created_by, created_on'.$fields.' FROM '.$final_tab.' WHERE true'.($admin?Utils_RecordBrowser::$admin_filter:' AND active=1').$where.' GROUP BY id HAVING true'.$having.$orderby,'vals'=>$vals);
		return $cache[$key] = $ret;
	}
	public static function get_records_limit( $tab = null, $crits = null, $admin = false) {
		$par = self::build_query($tab, $crits, $admin);
		if (empty($par)) return 0;
		return DB::GetOne('SELECT COUNT(*) FROM ('.$par['sql'].') AS tmp', $par['vals']);
	}
	public static function get_records( $tab = null, $crits = array(), $cols = array(), $order = array(), $limit = array(), $admin = false) {
		if (!$tab) return false;
		if (!isset($limit['offset'])) $limit['offset'] = 0;
		if (!isset($limit['numrows'])) $limit['numrows'] = -1;
		if (!$order) $order = array();
		if (count($crits)==1 && isset($crits['id']) && empty($order)) {
			self::init($tab);
			if (empty($crits['id'])) return array();
			if (!is_array($crits['id'])) $crits['id'] = array($crits['id']);
			$first = true;
			$where = '';
			$vals = array();
			foreach($crits['id'] as $v) { 
				if ($first) $first = false;
				else $where .= ', ';
				$where .= '%d';
				$vals[] = $v;
			}
			$ret = DB::SelectLimit('SELECT id, active, created_by, created_on FROM '.$tab.' WHERE id IN ('.$where.')', $limit['numrows'], $limit['offset'], $vals);
		} else {
			$par = self::build_query($tab, $crits, $admin, $order);
			if (empty($par)) return array();
			$ret = DB::SelectLimit($par['sql'], $limit['numrows'], $limit['offset'], $par['vals']);
		}
		$records = array();
		$where = ' WHERE true';
		$vals = array();
		$cols = array_flip($cols);
		if (!empty($cols)) {
			foreach(self::$table_rows as $field=>$args)
				if (isset($cols[$args['id']])) {
					unset($cols[$args['id']]);
					$cols[$field] = true;
				}
			$where .= ' AND (false';
			foreach ($cols as $k=>$v) {
				$where .= ' OR field=%s';
				$vals[] = $k;
			}
			$where .= ')';
		}
		$where .= ' AND '.$tab.'_id IN (';
		$first = true;
		while ($row = $ret->FetchRow()) {
			$records[$row['id']] = array(	'id'=>$row['id'],
											'active'=>$row['active'],
											'created_by'=>$row['created_by'],
											'created_on'=>$row['created_on']);
			if ($first) $first = false;
			else $where .= ', ';
			$where .= '%d';
			$vals[] = $row['id'];
		}
		if ($first) return array();
		$where .= ')';
		$data = DB::Execute('SELECT * FROM '.$tab.'_data'.$where, $vals);
		while($field = $data->FetchRow()) {
			if (!isset(self::$table_rows[$field['field']])) continue;
			$field_id = strtolower(str_replace(' ','_',$field['field']));
			if (self::$table_rows[$field['field']]['type'] == 'multiselect') {
				if (isset($records[$field[$tab.'_id']][$field_id]))
					$records[$field[$tab.'_id']][$field_id][] = $field['value'];
				else $records[$field[$tab.'_id']][$field_id] = array($field['value']);
			} else
				$records[$field[$tab.'_id']][$field_id] = $field['value'];
		}
		foreach(self::$table_rows as $field=>$args)
			if (empty($cols) || isset($cols[$field]))
				foreach($records as $k=>$v)
					if (!isset($records[$k][$args['id']]))
						if ($args['type'] == 'multiselect') $records[$k][$args['id']] = array();
						else $records[$k][$args['id']] = '';
		return $records;
	}
	public static function check_record_against_crits($tab, $id, $crits) {
		if ($crits===true || empty($crits)) return true;
		static $cache = array();
		if (isset($cache[$tab.'__'.$id])) return $cache[$tab.'__'.$id];
		$r = self::get_record($tab, $id);
		$or_started = false;
		$or_result = false;
		foreach ($crits as $k=>$v) {
			$negative = $noquotes = $or_start = $or = false;
			$operator = '==';
			while (($k[0]<'a' || $k[0]>'z') && ($k[0]<'A' || $k[0]>'Z')) {
				if ($k[0]=='!') $negative = true;
				if ($k[0]=='"') $noquotes = true;
				if ($k[0]=='(') $or_start = true;
				if ($k[0]=='|') $or = true;
				if ($k[0]=='<') $operator = '<';
				if ($k[0]=='>') $operator = '>';
				if ($k[1]=='=' && $operator!='==') {
					$operator .= '=';
					$k = substr($k, 2);
				} else $k = substr($k, 1);
				if (!isset($k[0])) trigger_error('Invalid criteria in build query: missing word.', E_USER_ERROR);
			}
			$or |= $or_start;
			if ($or) {
				if ($or_start && $or_started) {
					if (!$or_result) return $cache[$tab.'__'.$id] = false;
					$or_result = false;
				}
				if (!$or_started) $or_result = false;
				$or_started = true;
			} else {
				if ($or_started && !$or_result) return $cache[$tab.'__'.$id] = false;
				$or_started = false;
			}
			$result = false;
			$k = strtolower($k);
			if (!isset($r[$k])) trigger_error($k.'<br><br>'.print_r($r,true), E_USER_ERROR);
			if (is_array($r[$k])) $result = in_array($v, $r[$k]); 
			else switch ($operator) {
				case '>': $result = ($r[$k] > $v); break;
				case '>=': $result = ($r[$k] >= $v); break;
				case '<': $result = ($r[$k] < $v); break;
				case '<=': $result = ($r[$k] <= $v); break;
				case '==': $result = stristr((string)$r[$k],(string)$v);
			}
			if ($negative) $result = !$result;
			if ($or_started) $or_result |= $result;
			else if (!$result) return $cache[$tab.'__'.$id] = false; 
		}
		if ($or_started && !$or_result) return $cache[$tab.'__'.$id] = false;
		return $cache[$tab.'__'.$id] = true;
	}
	public static function get_access($tab, $action, $param=null){
		if (Base_AclCommon::i_am_admin()) return true;
		static $cache = array();
		if (!isset($cache[$tab])) $cache[$tab] = $access_callback = explode('::', DB::GetOne('SELECT access_callback FROM recordbrowser_table_properties WHERE tab=%s', array($tab)));
		else $access_callback = $cache[$tab];
		if ($access_callback === '' || !is_callable($access_callback)) return true;
		$ret = call_user_func($access_callback, $action, $param);
		if ($action==='delete' && $ret) $ret = call_user_func($access_callback, 'edit', $param);
		if ($action==='fields') {
			self::init($tab);			
			foreach (self::$table_rows as $field=>$args)
				if (!isset($ret[$args['id']])) $ret[$args['id']] = 'full';
		}
		return $ret;
	}
	public static function get_record_info($tab = null, $id = null) {
		if (!$tab) return false;
		if (!$id) return false;
		self::check_table_name($tab);
		$created = DB::GetRow('SELECT created_on, created_by FROM '.$tab.' WHERE id=%d', array($id));
		$edited = DB::GetRow('SELECT edited_on, edited_by FROM '.$tab.'_edit_history WHERE '.$tab.'_id=%d ORDER BY edited_on DESC', array($id));
		if (!isset($edited['edited_on'])) $edited['edited_on'] = null;
		if (!isset($edited['edited_by'])) $edited['edited_by'] = null;
		if (!isset($created['created_on'])) trigger_error('There is no such record as '.$id.' in table '.$tab, E_USER_ERROR);
		return array(	'created_on'=>$created['created_on'],'created_by'=>$created['created_by'],
						'edited_on'=>$edited['edited_on'],'edited_by'=>$edited['edited_by']);
	}
	public static function get_html_record_info($tab = null, $id = null){
		if (is_numeric($id))$info = Utils_RecordBrowserCommon::get_record_info($tab, $id);
		else $info = $id;
		$contact='';
		if (ModuleManager::is_installed('CRM_Contacts')>=0) {
			$contact = CRM_ContactsCommon::contact_format_no_company(CRM_ContactsCommon::get_contact_by_user_id($info['created_by']),true);
			if ($contact!=' ') $created_by = $contact;
			else $created_by = Base_UserCommon::get_user_login($info['created_by']);
			if ($info['edited_by']!=null) {
				if ($info['edited_by']!=$info['created_by']) $contact = CRM_ContactsCommon::contact_format_no_company(CRM_ContactsCommon::get_contact_by_user_id($info['edited_by']),true);
				if ($contact!=' ') $edited_by = $contact;
				else $edited_by = Base_UserCommon::get_user_login($info['edited_by']);
			}
		} else {
			$created_by = Base_UserCommon::get_user_login($info['created_by']);
			$edited_by = Base_UserCommon::get_user_login($info['edited_by']);
		}
		return Base_LangCommon::ts('Utils_RecordBrowser','Created on:').' '.Base_RegionalSettingsCommon::time2reg($info['created_on']). '<br>'.
				Base_LangCommon::ts('Utils_RecordBrowser','Created by:').' '.$created_by. '<br>'.
				(($info['edited_by']!=null)?(
				Base_LangCommon::ts('Utils_RecordBrowser','Edited on:').' '.Base_RegionalSettingsCommon::time2reg($info['edited_on']). '<br>'.
				Base_LangCommon::ts('Utils_RecordBrowser','Edited by:').' '.$edited_by):'');
	}
	public static function get_record( $tab, $id) {
		if (!is_numeric($id)) return null;
		self::init($tab);
		if (isset($id)) {
			self::check_table_name($tab);
			$row = DB::Execute('SELECT active, created_by, created_on FROM '.$tab.' WHERE id=%d', array($id))->FetchRow();
			if ($row===false) return null;
			$data = DB::Execute('SELECT * FROM '.$tab.'_data WHERE '.$tab.'_id=%d', array($id));
			$record = array();
			while($field = $data->FetchRow()) {
				if (!isset(self::$table_rows[$field['field']])) continue;
				$field_id = strtolower(str_replace(' ','_',$field['field']));
				if (self::$table_rows[$field['field']]['type'] == 'multiselect')
					if (isset($record[$field_id]))
						$record[$field_id][] = $field['value'];
					else $record[$field_id] = array($field['value']);
				else
					$record[$field_id] = $field['value'];
			}
			$record['id'] = $id;
			foreach(array('active','created_by','created_on') as $v)
				$record[$v] = $row[$v];
			foreach(self::$table_rows as $field=>$args)
				if (!isset($record[$args['id']]))
					if ($args['type'] == 'multiselect') $record[$args['id']] = array();
					else $record[$args['id']] = '';
			return $record;
		} else {
			return null;
		}
	}
	public static function delete_record($tab, $id) {
		self::check_table_name($tab);
		DB::StartTrans();
		DB::Execute('UPDATE '.$tab.' SET active=0 where id=%d', array($id));
		DB::Execute('INSERT INTO '.$tab.'_edit_history(edited_on, edited_by, '.$tab.'_id) VALUES (%T,%d,%d)', array(date('Y-m-d G:i:s'), Acl::get_user(), $id));
		$edit_id = DB::Insert_ID($tab.'_edit_history','id');
		DB::Execute('INSERT INTO '.$tab.'_edit_history_data(edit_id, field, old_value) VALUES (%d,%s,%s)', array($edit_id, '', 'DELETED'));
		DB::CompleteTrans();
	}
	public static function no_wrap($s) {
		$content_no_wrap = $s;
		preg_match_all('/>([^\<\>]*)</', $s, $match);
		foreach($match[1] as $v) $content_no_wrap = str_replace($v, str_replace(' ','&nbsp;', $v), $content_no_wrap);
		return $content_no_wrap;
	}
	public static function get_new_record_href($tab, $def, $id='none'){
		self::check_table_name($tab);
		$x = ModuleManager::get_instance('/Base_Box|0');
		if (!$x) trigger_error('There is no base box module instance',E_USER_ERROR);
		if (Utils_RecordBrowser::$clone_result!==null) {
			if (is_numeric(Utils_RecordBrowser::$clone_result)) $x->push_main('Utils/RecordBrowser','view_entry',array('view', Utils_RecordBrowser::$clone_result), array(Utils_RecordBrowser::$clone_tab));
			Utils_RecordBrowser::$clone_result = null;
		}
		if (isset($_REQUEST['__add_record_to_RB_table']) &&
			isset($_REQUEST['__add_record_id']) && 
			($tab==$_REQUEST['__add_record_to_RB_table']) &&
			($id==$_REQUEST['__add_record_id'])) {
			unset($_REQUEST['__add_record_to_RB_table']);
			unset($_REQUEST['__add_record_id']);
			$x->push_main('Utils/RecordBrowser','view_entry',array('add', null, $def), array($tab));
			return array();
		}
		return array('__add_record_to_RB_table'=>$tab, '__add_record_id'=>$id);
	}
	public static function create_new_record_href($tab, $def, $id='none'){
		return Module::create_href(self::get_new_record_href($tab,$def, $id));
	}
	public static function get_record_href_array($tab, $id){
		self::check_table_name($tab);
		if (isset($_REQUEST['__jump_to_RB_table']) && 
			($tab==$_REQUEST['__jump_to_RB_table']) &&
			($id==$_REQUEST['__jump_to_RB_record'])) {
			unset($_REQUEST['__jump_to_RB_record']);
			unset($_REQUEST['__jump_to_RB_table']);
			$x = ModuleManager::get_instance('/Base_Box|0');
			if (!$x) trigger_error('There is no base box module instance',E_USER_ERROR);
			$x->push_main('Utils/RecordBrowser','view_entry',array('view', $id),array($tab));
			return array();
		}
		return array('__jump_to_RB_table'=>$tab, '__jump_to_RB_record'=>$id);
	}
	private static function create_record_href($tab, $id){
		return Module::create_href(self::get_record_href_array($tab,$id));
	}
	public static function record_link_open_tag($tab, $id, $nolink=false){
		self::check_table_name($tab);
		if (!DB::GetOne('SELECT active FROM '.$tab.' WHERE id=%d',array($id))) {
			self::$del_or_a = '</del>';
			return '<del '.Utils_TooltipCommon::open_tag_attrs(Base_LangCommon::ts('Utils_RecordBrowser','This record was deleted from the system, please edit current record or contact system administrator')).'>';
		}
		if (!self::check_record_against_crits($tab, $id, self::get_access($tab, 'view'))) {
			self::$del_or_a = '</span>';
			return '<span '.Utils_TooltipCommon::open_tag_attrs(Base_LangCommon::ts('Utils_RecordBrowser','You don\'t have permission to view this record.')).'>';
		}
		self::$del_or_a = '</a>';
		if (!$nolink) return '<a '.self::create_record_href($tab, $id).'>';
		self::$del_or_a = '';
		return '';
	}
	public static function record_link_close_tag(){
		return self::$del_or_a;
	}
	public static function create_linked_label($tab, $col, $id, $nolink=false){
		if (!is_numeric($id)) return '';
		self::check_table_name($tab);
		self::init($tab);
		if (isset(self::$hash[$col])) $col = self::$hash[$col];
		$label = DB::GetOne('SELECT value FROM '.$tab.'_data WHERE field=%s AND '.$tab.'_id=%d', array($col, $id));
		return self::record_link_open_tag($tab, $id, $nolink).$label.self::record_link_close_tag();
	}
}
?>
