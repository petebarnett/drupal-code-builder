<?php

namespace DrupalCodeBuilder\Test\Unit;

use DrupalCodeBuilder\Test\Unit\Parsing\PHPTester;

/**
 * Tests basic module generation.
 *
 * @group hooks
 */
class ComponentModule8Test extends TestBase {

  /**
   * The Drupal core major version to set up for this test.
   *
   * @var int
   */
  protected $drupalMajorVersion = 8;

  /**
   * Test requesting a module with no options produces basic files.
   */
  function testNoOptions() {
    // Create a module.
    $module_name = 'testmodule8a';
    $module_data = array(
      'base' => 'module',
      'root_name' => $module_name,
      'readable_name' => 'Test module',
      'short_description' => 'Test Module description',
      'hooks' => array(
      ),
      'readme' => FALSE,
    );
    $files = $this->generateModuleFiles($module_data);
    $file_names = array_keys($files);

    $this->assertCount(1, $files, "One file is returned.");

    $this->assertArrayHasKey("$module_name.info.yml", $files, "The files list has a .info.yml file.");
  }

  /**
   * Test the helptext option produces hook_help().
   */
  function testHelptextOption() {
    // Create a module.
    $module_name = 'testmodule8b';
    $help_text = 'This is the test help text';
    $module_data = array(
      'base' => 'module',
      'root_name' => $module_name,
      'readable_name' => 'Test module',
      'short_description' => 'Test Module description',
      'hooks' => array(
      ),
      'readme' => FALSE,
      'module_help_text' => $help_text,
    );
    $files = $this->generateModuleFiles($module_data);
    $file_names = array_keys($files);

    $this->assertCount(2, $files, "Two files are returned.");

    $this->assertArrayHasKey("$module_name.module", $files, "The files list has a .module file.");
    $this->assertArrayHasKey("$module_name.info.yml", $files, "The files list has a .info.yml file.");

    // Check the .module file.
    $module_file = $files["$module_name.module"];

    $php_tester = new PHPTester($module_file);
    $php_tester->assertDrupalCodingStandards();

    $php_tester->assertHasHookImplementation('hook_help', $module_name);

    $this->assertFunctionCode($module_file, $module_name . '_help', $help_text, "The hook_help() implementation contains the requested help text.");
  }

}
