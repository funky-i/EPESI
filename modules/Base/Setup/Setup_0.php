<?php
/**
 * Setup class
 *
 * This file contains setup module.
 *
 * @author Paul Bukowski <pbukowski@telaxus.com> and Arkadiusz Bisaga <abisaga@telaxus.com>
 * @copyright Copyright &copy; 2008, Telaxus LLC
 * @license MIT
 * @version 1.0
 * @package epesi-base
 * @subpackage setup
 */
defined("_VALID_ACCESS") || die('Direct access forbidden');

/**
 * This class provides for administration of modules.
 */
class Base_Setup extends Module {
	private $store = false;

	public function construct() {
		$this->store = ModuleManager::is_installed('Base/EpesiStore');
	}

	public function admin() {
		$this->body();
	}
	
	public function body() {
		if($this->is_back() && $this->parent) {
			$this->parent->reset();
			return;
		}

		$post_install = & $this->get_module_variable('post-install');

		if(is_array($post_install)) {
			foreach($post_install as $i=>$v) {
				ModuleManager::include_install($i);
				$f = array($i.'Install','post_install');
				$fs = array($i.'Install','post_install_process');
				if(!is_callable($f) || !is_callable($fs)) {
					unset($post_install[$i]);
					continue;
				}
				$ret = call_user_func($f);
				$form = $this->init_module('Libs/QuickForm',null,$i);
				$form->addElement('header',null,'Post installation of '.str_replace('_','/',$i));
				$form->add_array($ret);
				$form->addElement('submit',null,'OK');
				if($form->validate()) {
					$form->process($fs);
					unset($post_install[$i]);
				} else {
					$form->display();
					break;
				}
			}
			if(empty($post_install))
				Epesi::redirect();
		}

		$post_install = & $this->get_module_variable('post-install');
		if(is_array($post_install)) 
			return;

		//create default module form
		$form = & $this->init_module('Libs/QuickForm','Processing modules','setup');
		
		$simple = Variable::get('simple_setup');

		//set defaults
		if(!$simple)
			$form->setDefaults(array (
				'default_module' => Variable::get('default_module'),
				'anonymous_setup' => Variable::get('anonymous_setup')));

		//install module header
		$form -> addElement('html','<tr><td colspan=2><br /><b>Please select modules to be installed/uninstalled.<br>For module details please click on "i" icon.</td></tr>');

		//show uninstalled & installed modules
		$ret = DB::Execute('SELECT * FROM available_modules');
		$module_dirs = array();
		while ($row = $ret->FetchRow()) {
			if (ModuleManager::exists($row['name'])) {
				$module_dirs[$row['name']][$row['vkey']] = $row['version'];
				ModuleManager::include_install($row['name']);
			} else {
				DB::Execute('DELETE FROM available_modules WHERE name=%s and vkey=%d',array($row['name'],$row['vkey']));
			}
		}
		if (empty($module_dirs))
			$module_dirs = Base_SetupCommon::refresh_available_modules();

		$subgroups = array();
		$structure = array();
		$def = array();
		$is_required = ModuleManager::required_modules(true);
		if(!$simple) {
		// transform is_required array to javascript
			eval_js('var deps = new Array('.sizeof($is_required).');');
			foreach($is_required as $k => $mod) {
				eval_js('deps["'.$k.'"] = new Array('.sizeof($mod).');');
				$i = 0;
				foreach($mod as $dep_mod) eval_js('deps["'.$k.'"]['.$i++.'] = "'.$dep_mod.'";');
			}
		// javascript to show warning only once and cascade uninstall
			eval_js('var showed = false;');
			eval_js_once('var original_select = new Array('.sizeof($is_required).');');
			eval_js_once('var mentioned = new Array;
						  function get_deps(mod) {
							var arr = new Array;
							if(mentioned[mod] == undefined) {
								arr.push(mod);
								mentioned[mod] = true;
							}
							if(deps[mod] == undefined) return arr;
							for(var i = 0; i < deps[mod].length; i++) {
								arr = arr.concat(get_deps(deps[mod][i]));
							}
							return arr;
						  };
						 function show_alert(x, mod) {
							if(x.options[x.selectedIndex].value == -2) {
								if(!showed) alert(\''.$this->t('Warning!\nAll data in reinstalled modules will be lost.').'\');
								showed=true;
								return;
							}
							if(x.selectedIndex != 0) {
								original_select[mod] = x.options[x.selectedIndex].value;
								return;
							}
							mentioned = new Array;
							var arr = get_deps(mod);
							if(arr.length == 1) return;
							var str = arr.length < 11 ? " - "+arr.join("\n - ") : arr.join(", ");
							if(confirm("'.$this->t('Warning! These modules will be deleted:').'\n" + str + "\n\n'.$this->t('Continue?').'") == false) {
								var ind = 0;
								for(; ind < x.options.length; ind++) if(x.options[ind].value == original_select[mod]) break;
								x.selectedIndex = ind;
								return;
							}
							for(var i = 0; i < arr.length; i++) {
								var el = document.getElementsByName("installed["+arr[i]+"]")[0];
								el.selectedIndex=0;
							}
					}');
		}
		foreach($module_dirs as $entry=>$versions) {
				$installed = ModuleManager::is_installed($entry);

				$module_install_class = $entry.'Install';
				$func_simple = array($module_install_class,'simple_setup');
				if (is_callable($func_simple))
					$simple_module = call_user_func($func_simple);
				else
					$simple_module = false;

				$func_info = array($module_install_class,'info');
				$info = '';
				if(is_callable($func_info)) {
					$module_info = call_user_func($func_info);
					if($module_info) {
						$info = ' <a '.Libs_LeightboxCommon::get_open_href($entry).'><img style="vertical-align: middle; cursor: pointer;" border="0" width="14" height="14" src='.Base_ThemeCommon::get_template_file('Base_Setup', 'info.png').'></a>';
						$iii = '<h1>'.str_replace('_','/',$entry).'</h1><table>';
						foreach($module_info as $k=>$v)
							$iii .= '<tr><td>'.$k.'</td><td>'.$v.'</td></tr>';
						$iii .= '</table>';
						Libs_LeightboxCommon::display($entry,$iii,'Additional info');
					}
				}
				if(isset($search) && $search && stripos($info,$search)===false && stripos($entry,$search)===false) continue;

				// Show Tooltip if module is required
				$tooltip = null;
				if(isset($is_required[$entry])) {
					if($simple) {
						$tooltip = $this->t('This module cannot be removed.').'<br/>';
						$tooltip .= ($is_required[$entry]>1 ? $this->t('Required by %d modules.', array($is_required[$entry])) : $this->t('Required by %d module.', array($is_required[$entry])));
					} else {
						$tooltip = $this->t('Required by:').'<ul>';
						foreach($is_required[$entry] as $mod_name) {
							$tooltip .= '<li>'.$mod_name.'</li>';
						}
						$tooltip .= '</ul>';
					}
				}

				if ($simple) {
					if ($simple_module===false) continue;
					if ($simple_module===true) $simple_module = array('package'=>'Uncategorized', 'option'=>$entry);
					if (is_string($simple_module)) $simple_module = array('package'=>$simple_module);
					if (!isset($simple_module['option'])) $simple_module['option'] = null;
					$simple_module['module'] = $entry;
					$simple_module['installed'] = ($installed>=0);
					$simple_module['key'] = $simple_module['package'].($simple_module['option']?'|'.$simple_module['option']:'');
					$structure[$entry] = $simple_module;
				} else {
					if(!isset($is_required[$entry]) || !$simple) $versions[-1]='not installed';
					ksort($versions);
					if($installed!=-1 && !isset($is_required[$entry])) $versions[-2] = 'reinstall';

					$path = explode('_',$entry);
					$c = & $structure;
					for($i=0, $path_count = count($path)-1;$i<$path_count;$i++){
						if(!array_key_exists($path[$i], $c)) {
							$c[$path[$i]] = array();
							$c[$path[$i]]['name'] = $path[$i];
							$c[$path[$i]]['sub'] = array();
						}
						$c = & $c[$path[$i]]['sub'];
					}
					$params_arr = $simple ? array('style'=>'width: 100px') : array('onChange'=>"show_alert(this,'$entry');", 'style'=>'width: 100px');
					$ele = $form->createElement('select', 'installed['.$entry.']', $path[count($path)-1], $versions, $params_arr);
					$ele->setValue($installed);
					if(!$simple) eval_js("original_select[\"$entry\"] = $installed");

					$c[$path[count($path)-1]] = array();
					$c[$path[count($path)-1]]['name'] = '<table width="400px"><tr><td align=left>' . $info . ' ' . $path[count($path)-1] . '</td><td width="100px" align=right '.($tooltip?Utils_TooltipCommon::open_tag_attrs($tooltip,false):'').'>' . $ele->toHtml() . '</td></tr></table>';
					$c[$path[count($path)-1]]['sub'] = array();
					array_push($def, array('installed['.$entry.']'=>$installed));
				}
		}

		if ($simple) {
			$packages = array();
			foreach ($structure as $s) {
				if (!isset($packages[$s['key']])) $packages[$s['key']] = array('also_uninstall'=>array(), 'modules'=>array(), 'is_required'=>array(), 'installed'=>null);
				$package = & $packages[$s['key']];
				$package['modules'][] = $s['module'];
				$package['name'] = $s['package'];
				$package['option'] = $s['option'];
				if ($package['installed']===null) {
					$package['installed'] = $s['installed'];
				} else {
					if (($s['installed'] && !$package['installed']) || (!$s['installed'] && $package['installed'])) {
						$package['installed'] = 'partial';
					}
				}
				if (!isset($is_required[$s['module']])) $is_required[$s['module']] = array();
				foreach ($is_required[$s['module']] as $r) {
					if (!isset($structure[$r])) {
						$package['also_uninstall'][] = $r;
						continue;
					}
					if ($structure[$r]['package']==$s['package']) continue;
					$package['is_required'][$structure[$r]['key']] = $structure[$r]['key'];
				}
			}
			$sorted = array();
			foreach ($packages as $key=>$p) {
				if ($key===0) continue;
				$name = $p['name'];
				$option = $p['option'];
				if (!isset($sorted[$name])) {
					$sorted[$name] = array();
					$sorted[$name]['modules'] = array();
					$sorted[$name]['buttons'] = array();
					$sorted[$name]['options'] = array();
					$sorted[$name]['status'] = 'Options only';
					$sorted[$name]['style'] = 'disabled';
					$sorted[$name]['installed'] = 0;
					$sorted[$name]['instalable'] = 0;
					$sorted[$name]['uninstalable'] = 0;
				}

				$buttons = array();
				$status = '';
				if ($p['installed']===true || $p['installed']==='partial') {
					if ($key!='epesi Core' && empty($p['is_required'])) {
						$mods = $p['modules'];
						foreach ($p['also_uninstall'] as $pp)
							$mods[] = $pp;
						if ($p['option']===null) { // also add all options as available for uninstall
							foreach ($packages as $pp)
								if ($pp['name']===$p['name']) {
									$mods = array_merge($mods,$pp['modules']);
								}
						}
						$buttons[] = array('label'=>'Uninstall','style'=>'uninstall','href'=>$this->create_callback_href(array($this, 'simple_uninstall'), array($mods)));
					} else {
						if ($key=='epesi Core') $message = $this->t('You may not uninstall epesi Core modules');
						elseif (empty($p['is_required'])) $message = $this->t('This package can not be uninstalled');
						else {
							$required = array();
							foreach ($p['is_required'] as $v) $required[] = str_replace('|',' / ', $v);
							$message = $this->t('This package is required by the following packages: %s',array('<br>'.implode('<br>', $required)));
						}
						$buttons[] = array('label'=>'Uninstall','style'=>'disabled','href'=>Utils_TooltipCommon::open_tag_attrs($message, false));
					}
				}
				if ($p['installed']===false || $p['installed']==='partial') {
					$buttons[] = array('label'=>'Install','style'=>'install','href'=>$this->create_callback_href(array($this, 'simple_install'), array($p['modules'])));
				}
				switch (true) {
					case $p['installed']===false:
						$style = 'available';
						$status = 'Available'; 
						break;
					case $p['installed']===true:
						$style = 'install';
						$status = 'Installed';
						break;
					case $p['installed']==='partial':
						$style = 'problem';
						$status = 'Partially';
						break;
				}

				if ($option===null) {
					$sorted[$name]['modules'] = $p['modules'];
					$sorted[$name]['buttons'] = $buttons;
					$sorted[$name]['status'] = $status;
					$sorted[$name]['style'] = $style;
					$sorted[$name]['installed'] = $p['installed'];
					$sorted[$name]['instalable'] = 1;
					$sorted[$name]['uninstalable'] = empty($p['is_required']);
				} else {
					$sorted[$name]['options'][$option] = array(
					'buttons' => $buttons,
					'status' => $status,
					'style' => $style);
				}
			}
			foreach ($sorted as $name=>$v)
				ksort($sorted[$name]['options']);
			$t = $this->init_module('Base/Theme');
			$t->assign('packages', $sorted);
			$t->display();
			Base_ActionBarCommon::add('settings', 'Advanced view',$this->create_confirm_callback_href('Switch to advanced view?',array($this,'switch_simple'),false));
		} else {
			$tree = & $this->init_module('Utils/Tree');
			$tree->set_structure($structure);
			$tree->set_inline_display();
			if ($simple) $tree->open_all();
			//$form->addElement('html', '<tr><td colspan=2>'.$tree->toHtml().'</td></tr>');
			$form->addElement('html', '<tr><td colspan=2>'.$this->get_html_of_module($tree).'</td></tr>');

			if(!$simple) {
				$form->addElement('header', 'anonymous_header', 'Other (dangerous, don\'t change if you are newbie)');
				$form->addElement('checkbox','anonymous_setup', 'Anonymous setup');

				//default module
				$av_modules=array();
				foreach(ModuleManager::$modules as $name=>$obj)
					$av_modules[$name] = $name;
				$form->addElement('select','default_module','Default module to display',$av_modules);
			}

			$form->setDefaults($def);

			//validation or display
			if ($form->exportValue('submited') && $form->validate()) {
				ob_start();
				if (!$this->validate($form->getSubmitValues()))
					print('<hr class="line"><center><a class="button"' . $this -> create_href(array()) . '>Back</a></center>');
				ob_end_clean();
				return;
			} else {
				$form->display();
				Base_ActionBarCommon::add('save', 'Save', $form->get_submit_form_href());
				Base_ActionBarCommon::add('settings', 'Simple view',$this->create_callback_href(array($this,'switch_simple'),true));
			}
		}
		Base_ActionBarCommon::add('scan','Rebuild modules database',$this->create_confirm_callback_href('Parsing for additional modules may take up to several minutes, do you wish to continue?',array('Base_Setup','parse_modules_folder_refresh')));
		Base_ActionBarCommon::add('back', 'Back', $this->create_back_href());
	}
	
	public function simple_uninstall($modules) {
		if(DEMO_MODE) {
			Base_StatusBarCommon::message('Feature unavailable in DEMO','warning');
			return;
		}
		ob_start();
		$modules_prio_rev = array();
		foreach (ModuleManager::$modules as $k => $v)
			$modules_prio_rev[] = $k;
		$modules_prio_rev = array_reverse($modules_prio_rev);

		foreach ($modules_prio_rev as $k)
			if(in_array($k, $modules))
				if (!ModuleManager::uninstall($k)) {
					ob_end_clean();
					Base_StatusBarCommon::message('Couldn\'t uninstall the package.','error');
					return false;
				}
		ob_end_clean();
		Base_StatusBarCommon::message('Package uninstalled.');
		return false;
	}
	
	public function simple_install($modules) {
		if(DEMO_MODE) {
			Base_StatusBarCommon::message('Feature unavailable in DEMO','warning');
			return;
		}
		ob_start();
		foreach ($modules as $k)
			if (!ModuleManager::install($k)) {
				ob_end_clean();
				Base_StatusBarCommon::message('Couldn\'t install the package.','error');
				return false;
			}
		ob_end_clean();
		Base_StatusBarCommon::message('Package installed.');
		return false;
	}
	
	public function store() {
	    $this->pack_module('Base_EpesiStore',array(),'admin');
	}
	
	public function switch_simple($a) {
		Variable::set('simple_setup',$a);
	}

	public static function parse_modules_folder_refresh(){
		Base_SetupCommon::refresh_available_modules();
		//location(array());
		return false;
	}

	public function validate($data) {
		if(DEMO_MODE) {
			print('You cannot modify installed modules in demo');
	    		return false;
		}

		@set_time_limit(0);
		
		$default_module = false;
		$simple = 0;
		$installed = array ();
		$install = array ();
		$uninstall = array();
		$anonymous_setup = false;

		foreach ($data as $k => $v)
			${ $k } = $v;

		if (!$simple) {
			if($default_module!==false)
				Variable::set('default_module', $default_module);
			Variable::set('anonymous_setup', $anonymous_setup);
		}
		Variable::set('simple_setup', $simple);

		foreach ($installed as $name => $new_version) {
			$old_version = ModuleManager::is_installed($name);
			if($old_version==$new_version) continue;
			if($old_version==-1 && $new_version>=0) {
				$install[$name]=$new_version;
				continue;
			}
            if($new_version==-2) {
                $uninstall[$name]=1;
                $install[$name]=$old_version;
                continue;
            }
			if($old_version>=0 && $new_version==-1) {
				$uninstall[$name]=1;
				continue;
			}
			if($old_version<$new_version) {
				if(!ModuleManager::upgrade($name, $new_version))
					return false;
				continue;
			}
			if($old_version>$new_version) {
				if(!ModuleManager::downgrade($name, $new_version))
					return false;
				continue;
			}
		}

		//uninstall
		$modules_prio_rev = array();
		foreach (ModuleManager::$modules as $k => $v)
			$modules_prio_rev[] = $k;
		$modules_prio_rev = array_reverse($modules_prio_rev);

		foreach ($modules_prio_rev as $k)
			if(array_key_exists($k, $uninstall)) {
				if (!ModuleManager::uninstall($k)) {
					return false;
				}
				if(count(ModuleManager::$modules)==0)
					print('No modules installed');
			}

        //install
		foreach($install as $i=>$v) {
			$post_install[$i] = $v;
            if(isset($uninstall[$i])) {
                if (!ModuleManager::install($i,$v,true,false))
                    return false;
            } else {
                if (!ModuleManager::install($i,$v))
                    return false;
            }
		}
		$processed = ModuleManager::get_processed_modules();
		$this->set_module_variable('post-install',$processed['install']);

        Base_ThemeCommon::create_cache();

		if(empty($post_install))
			Epesi::redirect();

		return true;
	}
}
?>
