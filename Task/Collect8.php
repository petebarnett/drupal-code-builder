<?php

/**
 * @file
 * Contains DrupalCodeBuilder\Task\Collect8.
 */

namespace DrupalCodeBuilder\Task;

/**
 * Task handler for collecting and processing component definitions.
 *
 * This collects data on hooks and plugin types.
 */
class Collect8 extends Collect {

  /**
   * Collect data about Drupal components from the current site's codebase.
   */
  public function collectComponentData() {
    $this->collectHooks();
    $this->collectPlugins();
    $this->collectServices();
  }

  /**
   * Collect data about plugin types and process it.
   */
  protected function collectPlugins() {
    $plugin_manager_service_ids = $this->getPluginManagerServices();

    $plugin_type_data = $this->gatherPluginTypeInfo($plugin_manager_service_ids);

    // Save the data.
    $this->writeProcessedData($plugin_type_data, 'plugins');
  }

  /**
   * Detects services which are plugin managers.
   *
   * @return
   *  An array of service IDs of all the services which we detected to be plugin
   *  managers.
   */
  protected function getPluginManagerServices() {
    // Get the IDs of all services from the container.
    $service_ids = \Drupal::getContainer()->getServiceIds();
    //drush_print_r($service_ids);

    // Filter them down to the ones that are plugin managers.
    // TODO: this omits some that don't conform to this pattern! Deal with
    // these! See https://www.drupal.org/node/2086181
    $plugin_manager_service_ids = array_filter($service_ids, function($element) {
      if (strpos($element, 'plugin.manager.') === 0) {
        return TRUE;
      }
    });

    //drush_print_r($plugin_manager_service_ids);

    // Developer trapdoor: just process the block plugin type, to make terminal
    // debug output easier to read through.
    //$plugin_manager_service_ids = array('plugin.manager.block');

    return $plugin_manager_service_ids;
  }

  /**
   * Detects information about plugin types from the plugin manager services
   *
   * @param $plugin_manager_service_ids
   *  An array of service IDs.
   *
   * @return
   *  The assembled plugin type data.
   *  TODO: document this.
   */
  protected function gatherPluginTypeInfo($plugin_manager_service_ids) {
    // Get plugin type information if Plugin module is present.
    // This gets us labels for some plugin types (though not all, as the plugin
    // type ID used by Plugin module doesn't always match the ID we get from
    // the service definition, e.g. views_access vs views.access).
    if (\Drupal::hasService('plugin.plugin_type_manager')) {
      $plugin_types = \Drupal::service('plugin.plugin_type_manager')->getPluginTypes();
    }

    // Assemble data from each plugin manager.
    $plugin_type_data = array();
    foreach ($plugin_manager_service_ids as $plugin_manager_service_id) {
      // Get the class for the service.
      $plugin_manager = \Drupal::service($plugin_manager_service_id);
      $plugin_manager_class = get_class($plugin_manager);

      //drush_print_r("$plugin_manager_service_id -> $plugin_manager_class");

      // Get a reflection class for the plugin manager class.
      $plugin_manager_reflection = new \ReflectionClass($plugin_manager_class);

      // Get the lines of code body for the constructor method.
      $constructor = $plugin_manager_reflection->getConstructor();
      $filename = $constructor->getFileName();
      $start_line = $constructor->getStartLine();
      $end_line = $constructor->getEndLine();
      $length = $end_line - $start_line;
      $source = file($filename);
      $lines = array_slice($source, $start_line, $length);

      // Find the call to the parent constructor. This should be the first line.
      // WARNING! This will BREAK if the call has a linebreak in it!
      // TODO: Consider allowing for that!
      $parent_constructor_call = NULL;
      foreach ($lines as $line) {
        if (preg_match('@\s*parent::__construct@', $line)) {
          $parent_constructor_call = $line;
          break;
        }
      }
      if (empty($parent_constructor_call)) {
        // We can't find the parent constructor call -- this plugin manager is
        // doing something different.
        // TODO: show a notice in all environments.
        //drush_print("Unable to find call to parent constructor in plugin manager class constructor method for service $plugin_manager_service_id, class $plugin_manager.");
        continue;
      }

      // The call to the constructor's parent method should be in this form:
      //   parent::__construct('Plugin/Block', $namespaces, $module_handler, 'Drupal\Core\Block\BlockPluginInterface', 'Drupal\Core\Block\Annotation\Block');
      // See Drupal\Core\Plugin\DefaultPluginManager for detail on these.
      // Use PHP's tokenizer to get the string parameters.
      // We need to add a PHP open tag for that to work.
      $tokens = token_get_all('<?php ' . $parent_constructor_call);

      // Go through the tokens and get the constant strings: these are the
      // parameters to the call that we want.
      $constant_string_parameters = array();
      foreach ($tokens as $token) {
        // For some reason, commas are not turned into tokens but appear as raw
        // strings! WTF?!
        if (!is_array($token)) {
          continue;
        }

        $token_name = token_name($token[0]);
        if ($token_name == 'T_CONSTANT_ENCAPSED_STRING') {
          // These come with the quotation marks, so we have to trim those.
          $constant_string_parameters[] = substr($token[1], 1, -1);
        }
      }

      // We identify plugin types by the part of the plugin manager service name
      // that comes after 'plugin.manager.'.
      $plugin_type_id = substr($plugin_manager_service_id, strlen('plugin.manager.'));

      $data = array(
        'type_id' => $plugin_type_id,
        'type_label' => isset($plugin_types[$plugin_type_id]) ?
          $plugin_types[$plugin_type_id]->getLabel() : $plugin_type_id,
        'service_id' => $plugin_manager_service_id,
        'subdir' => isset($constant_string_parameters[0]) ?
          // Some plugin managers, e.g. ViewsHandlerManager, get us nothing for
          // this.
          $constant_string_parameters[0] : 'Plugin/Subdir',
        // These two are optional parameters for
        // Drupal\Core\Plugin\DefaultPluginManager::__construct(), and so might
        // not be present.
        'plugin_interface' => isset($constant_string_parameters[1]) ?
          $constant_string_parameters[1] : NULL,
        'plugin_definition_annotation_name' => isset($constant_string_parameters[2]) ?
            $constant_string_parameters[2] : 'Drupal\Component\Annotation\Plugin',
      );

      // Analyze the interface, if there is one.
      if (empty($data['plugin_interface'])) {
        $data['plugin_interface_methods'] = array();
      }
      else {
        // Get a reflection class for the interface.
        $plugin_interface_reflection = new \ReflectionClass($data['plugin_interface']);
        $methods = $plugin_interface_reflection->getMethods();

        foreach ($methods as $method) {
          $interface_method_data = array();

          $interface_method_data['name'] = $method->getName();

          // Methods may be in parent interfaces, so not all in the same file.
          $filename = $method->getFileName();
          $source = file($filename);
          $start_line = $method->getStartLine();

          // Trim whitespace from the front, as this will be indented.
          $interface_method_data['declaration'] = trim($source[$start_line - 1]);

          // Get the docblock for the method.
          $method_docblock_lines = explode("\n", $method->getDocComment());
          foreach ($method_docblock_lines as $line) {
            // Take the first actual docblock line to be the description.
            if (substr($line, 0, 5) == '   * ') {
              $interface_method_data['description'] = substr($line, 5);
              break;
            }
          }

          $data['plugin_interface_methods'][$method->getName()] = $interface_method_data;
        }
      }

      // Now analyze the anotation.
      // Get a reflection class for the annotation class.
      // Each property of the annotation class describes a property for the
      // plugin annotation.
      $annotation_reflection = new \ReflectionClass($data['plugin_definition_annotation_name']);
      $properties_reflection = $annotation_reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

      $plugin_properties = array();
      foreach ($properties_reflection as $property_reflection) {
        // Assemble data about this annotation property.
        $annotation_property_data = array();
        $annotation_property_data['name'] = $property_reflection->name;

        // Get the docblock for the property, so we can figure out whether the
        // annotation property requires translation, and also add detail to the
        // annotation code.
        $property_docblock = $property_reflection->getDocComment();
        $property_docblock_lines = explode("\n", $property_docblock);
        foreach ($property_docblock_lines as $line) {
          if (substr($line, 0, 3) == '/**') {
            continue;
          }

          // Take the first actual docblock line to be the description.
          if (!isset($annotation_property_data['description']) && substr($line, 0, 5) == '   * ') {
            $annotation_property_data['description'] = substr($line, 5);
          }

          // Look for a @var token, to tell us the type of the property.
          if (substr($line, 0, 10) == '   * @var ') {
            $annotation_property_data['type'] = substr($line, 10);
          }
        }

        $plugin_properties[$property_reflection->name] = $annotation_property_data;
      }

      $data['plugin_properties'] = $plugin_properties;

      $plugin_type_data[$plugin_type_id] = $data;
    }

    //drush_print_r($plugin_type_data);

    return $plugin_type_data;
  }

  /**
   * Collect data about services.
   */
  protected function collectServices() {
    $service_definitions = $this->gatherServiceDefinitions();

    // Save the data.
    $this->writeProcessedData($service_definitions, 'services');
  }

  /**
   * Get definitions of services from the static container.
   *
   * We collect an incomplete list of services, namely, those which have special
   * methods in the \Drupal static container. This is because (AFAIK) these are
   * the only ones for which we can detect the interface and a description.
   */
  protected function gatherServiceDefinitions() {
    // We can get service IDs from the container,
    $static_container_reflection = new \ReflectionClass('\Drupal');
    $filename = $static_container_reflection->getFileName();
    $source = file($filename);

    $methods = $static_container_reflection->getMethods();
    $service_definitions = [];
    foreach ($methods as $method) {
      $name = $method->getName();

      // Skip any which have parameters: the service getter methods have no
      // parameters.
      if ($method->getNumberOfParameters > 0) {
        continue;
      }

      $start_line = $method->getStartLine();
      $end_line = $method->getEndLine();

      // Skip any which have more than 2 lines: the service getter methods have
      // only 1 line of code.
      if ($end_line - $start_line > 2) {
        continue;
      }

      // Get the single code line.
      $code_line = $source[$start_line];

      // Extract the service ID from the call to getContainer().
      $matches = [];
      $code_line_regex = "@return static::getContainer\(\)->get\('([\w.]+)'\);@";
      if (!preg_match($code_line_regex, $code_line, $matches)) {
        continue;
      }
      $service_id = $matches[1];

      $docblock = $method->getDocComment();

      // Extract the interface for the service from the docblock @return.
      $matches = [];
      preg_match("[@return (.+)]", $docblock, $matches);
      $interface = $matches[1];

      // Extract a description from the docblock first line.
      $docblock_lines = explode("\n", $docblock);
      $doc_first_line = $docblock_lines[1];

      $matches = [];
      preg_match("@(the (.*))\.@", $doc_first_line, $matches);
      $description = ucfirst($matches[1]);
      $label = ucfirst($matches[2]);

      $service_definition = [
        'id' => $service_id,
        'label' => $label,
        'static_method' => $name,
        'interface' => $interface,
        'description' => $description,
      ];
      $service_definitions[$service_id] = $service_definition;
    }

    return $service_definitions;
  }

  /**
   * Gather hook documentation files.
   *
   * This retrieves a list of api hook documentation files from the current
   * Drupal install. On D8 these are files of the form MODULE.api.php and are
   * present in the codebase (rather than needing to be downloaded from an
   * online code repository viewer as is the case in previous versions of
   * Drupal).
   */
  protected function gatherHookDocumentationFiles() {
    // Get the hooks directory.
    $directory = \DrupalCodeBuilder\Factory::getEnvironment()->getHooksDirectory();

    // Get Drupal root folder as a file path.
    // DRUPAL_ROOT is defined both by Drupal and Drush.
    // @see _drush_bootstrap_drupal_root(), index.php.
    $drupal_root = DRUPAL_ROOT;

    $system_listing = \DrupalCodeBuilder\Factory::getEnvironment()->systemListing('/\.api\.php$/', 'modules', 'filename');
    // returns an array of objects, properties: uri, filename, name,
    // keyed by filename, eg 'comment.api.php'
    // What this does not give us is the originating module!

    // Add in api.php files in core/lib.
    $core_directory = new \RecursiveDirectoryIterator('core/lib/Drupal');
    $iterator = new \RecursiveIteratorIterator($core_directory);
    $regex = new \RegexIterator($iterator, '/^.+\.api.php$/i', \RecursiveRegexIterator::GET_MATCH);
    $core_api_files = [];
    foreach ($regex as $regex_files) {
      foreach ($regex_files as $file) {
        $filename = basename($file);

        $component_name = explode('.', $filename)[0];
        $system_listing['core:' . $filename] = (object) array(
          'uri' => $file,
          'filename' => $filename,
          'name' => basename($file, '.php'),
          'group' => 'core:' . $component_name,
          'module' => 'core',
        );
      }
    }

    // Add in core.api.php, which won't have been picked up because it's not
    // in a module!
    $system_listing['core.api.php'] = (object) array(
      'uri' => 'core/core.api.php',
      'filename' => 'core.api.php',
      'name' => 'core.api',
      'group' => 'core:core',
      'module' => 'core',
    );

    //print_r($system_listing);

    foreach ($system_listing as $key => $file) {
      // Extract the module name from the path.
      // WARNING: this is not always going to be correct: will fail in the
      // case of submodules. So Commerce is a big problem here.
      // We could instead assume we have MODULE.api.php, but some modules
      // have multiple API files with suffixed names, eg Services.
      // @todo: make this more robust, somehow!
      if (!isset($file->module)) {
        $matches = array();
        preg_match('@modules/(?:contrib/)?(\w+)@', $file->uri, $matches);
        //print_r($matches);
        $file->module = $matches[1];
        $file->group = $file->module;
      }
      //dsm($matches, $module);

      // Mark core files.
      $core = (substr($file->uri, 0, 4) == 'core');

      // Copy the file to the hooks directory.
      copy($drupal_root . '/' . $file->uri, $directory . '/' . $file->filename);

      $hook_files[$key] = array(
        'original' => $drupal_root . '/' . $file->uri, // no idea if useful
        'path' => $directory . '/' . $file->filename,
        'destination' => '%module.module', // Default. We override this below.
        'group'       => $file->group,
        'module'      => $file->module,
        'core'        => $core,
      );
    }

    // We now have the basics.
    // We should now see if some modules have extra information for us.
    $this->getHookDestinations($hook_files);

    return $hook_files;
  }

  /**
   * Add extra data about hook destinations to the hook file data.
   *
   * This allows entire files or individual hooks to have a file other than
   * the default %module.module as their destination.
   */
  private function getHookDestinations(&$hook_files) {
    // Get our data.
    $data = $this->getHookInfo();

    // Incoming data is destination key, array of hooks.
    // (Because it makes typing the data out easier! Computers can just adapt.)
    foreach ($data as $module => $module_data) {
      // The key in $hook_files we correspond to
      // @todo, possibly: this feels like slightly shaky ground.
      $filename = "$module.api.php";

      // Skip filenames we haven't already found, so we don't pollute our data
      // array with hook destination data for files that don't exist here.
      if (!isset($hook_files[$filename])) {
        continue;
      }

      // The module data can set a single destination for all its hooks.
      if (isset($module_data['destination'])) {
        $hook_files[$filename]['destination'] = $module_data['destination'];
      }
      // It can also (or instead) set a destination per hook.
      if (isset($module_data['hook_destinations'])) {
        $hook_files[$filename]['hook_destinations'] = array();
        foreach ($module_data['hook_destinations'] as $destination => $hooks) {
          $destinations[$module] = array_fill_keys($hooks, $destination);
          $hook_files[$filename]['hook_destinations'] += array_fill_keys($hooks, $destination);
        }
      }

      // Add the dependencies array as it comes; it will be processed per hook later.
      if (isset($module_data['hook_dependencies'])) {
        $hook_files[$filename]['hook_dependencies'] = $module_data['hook_dependencies'];
      }
    }

    //print_r($hook_files);
  }

  /**
   * Get info about hooks from Drupal.
   *
   * This invokes hook_hook_info().
   */
  protected function getDrupalHookInfo($file_data) {
    // Note that the 'module' key is flaky: some modules use a different name
    // for their api.php file.
    $module = $file_data['module'];

    // Bail for 'core' pseudomodule.
    if ($module == 'core') {
      return [];
    }

    $hook_info = array();
    if (\Drupal::moduleHandler()->implementsHook($module, 'hook_info')) {
      $hook_info = \Drupal::moduleHandler()->invoke($module, 'hook_info');
    }

    return $hook_info;
  }

  /**
   * {@inheritdoc}
   */
  protected function getAdditionalHookInfo() {
    // Keys should match the filename MODULE.api.php
    $info = array(
      // Hooks on behalf of Drupal core.
      'module' => array(
        'hook_destinations' => array(
          '%module.install' => array(
            'hook_requirements',
            'hook_schema',
            'hook_schema_alter',
            'hook_install',
            'hook_update_N',
            'hook_update_last_removed',
            'hook_uninstall',
          ),
        ),
      ),
    );
    return $info;
  }

}
