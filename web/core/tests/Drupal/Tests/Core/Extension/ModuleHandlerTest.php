<?php

declare(strict_types=1);

namespace Drupal\Tests\Core\Extension;

use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Extension\Exception\UnknownExtensionException;
use Drupal\Core\Extension\ProceduralCall;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\Core\GroupIncludesTestTrait;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @coversDefaultClass \Drupal\Core\Extension\ModuleHandler
 * @runTestsInSeparateProcesses
 *
 * @group Extension
 */
class ModuleHandlerTest extends UnitTestCase {

  use GroupIncludesTestTrait;

  /**
   * @var \PHPUnit\Framework\MockObject\MockObject|\Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * {@inheritdoc}
   *
   * @covers ::__construct
   */
  protected function setUp(): void {
    parent::setUp();
    $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
  }

  /**
   * Get a module handler object to test.
   *
   * Since we have to run these tests in separate processes, we have to use
   * test objects which are serializable. Since ModuleHandler will populate
   * itself with Extension objects, and since Extension objects will try to
   * access DRUPAL_ROOT when they're unserialized, we can't store our mocked
   * ModuleHandler objects as a property in unit tests. They must be generated
   * by the test method by calling this method.
   *
   * @return \Drupal\Core\Extension\ModuleHandler
   *   The module handler to test.
   */
  protected function getModuleHandler($implementations = []) {
    $module_handler = new ModuleHandler($this->root, [], $this->eventDispatcher, $implementations);
    $module_handler->addModule('module_handler_test', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test');
    return $module_handler;
  }

  /**
   * Tests loading a module.
   *
   * @covers ::load
   */
  public function testLoadModule(): void {
    $module_handler = $this->getModuleHandler();
    $this->assertTrue($module_handler->load('module_handler_test'));
    $this->assertTrue(function_exists('module_handler_test_hook'));

    $module_handler->addModule('module_handler_test_added', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test_added');
    $this->assertTrue($module_handler->load('module_handler_test_added'));
    $this->assertTrue(function_exists('module_handler_test_added_helper'), 'Function exists after being loaded.');
    $this->assertTrue($module_handler->load('module_handler_test_added'));

    $this->assertFalse($module_handler->load('module_handler_test_dne'), 'Non-existent modules returns false.');
  }

  /**
   * Tests loading all modules.
   *
   * @covers ::loadAll
   */
  public function testLoadAllModules(): void {
    $module_handler = $this->getModuleHandler();
    $module_handler->addModule('module_handler_test_all1', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test_all1');
    $module_handler->addModule('module_handler_test_all2', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test_all2');
    $module_handler->loadAll();
    $this->assertTrue(function_exists('module_handler_test_all1_hook'), 'Function exists after being loaded.');
    $this->assertTrue(function_exists('module_handler_test_all2_hook'), 'Function exists after being loaded.');
  }

  /**
   * Tests reload method.
   *
   * @covers ::reload
   */
  public function testModuleReloading(): void {
    $module_handler = $this->getMockBuilder(ModuleHandler::class)
      ->setConstructorArgs([
        $this->root,
        [
          'module_handler_test' => [
            'type' => 'module',
            'pathname' => 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test/module_handler_test.info.yml',
            'filename' => 'module_handler_test.module',
          ],
        ], $this->eventDispatcher, [],
      ])
      ->onlyMethods(['load'])
      ->getMock();
    $calls = [
      // First reload.
      'module_handler_test',
      // Second reload.
      'module_handler_test',
      'module_handler_test_added',
    ];
    $module_handler->expects($this->exactly(count($calls)))
      ->method('load')
      ->with($this->callback(function (string $module) use (&$calls): bool {
        return $module === array_shift($calls);
      }));
    $module_handler->reload();
    $module_handler->addModule('module_handler_test_added', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test_added');
    $module_handler->reload();
  }

  /**
   * Tests isLoaded accessor.
   *
   * @covers ::isLoaded
   */
  public function testIsLoaded(): void {
    $module_handler = $this->getModuleHandler();
    $this->assertFalse($module_handler->isLoaded());
    $module_handler->loadAll();
    $this->assertTrue($module_handler->isLoaded());
  }

  /**
   * Confirm we get back the modules set in the constructor.
   *
   * @covers ::getModuleList
   */
  public function testGetModuleList(): void {
    $this->assertEquals($this->getModuleHandler()->getModuleList(), [
      'module_handler_test' => new Extension($this->root, 'module', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test/module_handler_test.info.yml', 'module_handler_test.module'),
    ]);
  }

  /**
   * Confirm we get back a module from the module list.
   *
   * @covers ::getModule
   */
  public function testGetModuleWithExistingModule(): void {
    $this->assertEquals($this->getModuleHandler()->getModule('module_handler_test'), new Extension($this->root, 'module', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test/module_handler_test.info.yml', 'module_handler_test.module'));
  }

  /**
   * @covers ::getModule
   */
  public function testGetModuleWithNonExistingModule(): void {
    $this->expectException(UnknownExtensionException::class);
    $this->getModuleHandler()->getModule('claire_alice_watch_my_little_pony_module_that_does_not_exist');
  }

  /**
   * Ensure setting the module list replaces the module list and resets internal structures.
   *
   * @covers ::setModuleList
   */
  public function testSetModuleList(): void {
    $fixture_module_handler = $this->getModuleHandler();
    $module_handler = $this->getMockBuilder(ModuleHandler::class)
      ->setConstructorArgs([
        $this->root, [], $this->eventDispatcher, [],
      ])
      ->onlyMethods(['resetImplementations'])
      ->getMock();

    // Ensure we reset implementations when settings a new modules list.
    $module_handler->expects($this->once())->method('resetImplementations');

    // Make sure we're starting empty.
    $this->assertEquals([], $module_handler->getModuleList());

    // Replace the list with a prebuilt list.
    $module_handler->setModuleList($fixture_module_handler->getModuleList());

    // Ensure those changes are stored.
    $this->assertEquals($fixture_module_handler->getModuleList(), $module_handler->getModuleList());
  }

  /**
   * Tests adding a module.
   *
   * @covers ::addModule
   * @covers ::add
   */
  public function testAddModule(): void {

    $module_handler = $this->getMockBuilder(ModuleHandler::class)
      ->setConstructorArgs([
        $this->root, [], $this->eventDispatcher, [],
      ])
      ->onlyMethods(['resetImplementations'])
      ->getMock();

    // Ensure we reset implementations when settings a new modules list.
    $module_handler->expects($this->once())->method('resetImplementations');

    $module_handler->addModule('module_handler_test', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test');
    $this->assertTrue($module_handler->moduleExists('module_handler_test'));
  }

  /**
   * Tests adding a profile.
   *
   * @covers ::addProfile
   * @covers ::add
   */
  public function testAddProfile(): void {

    $module_handler = $this->getMockBuilder(ModuleHandler::class)
      ->setConstructorArgs([
        $this->root, [], $this->eventDispatcher, [],
      ])
      ->onlyMethods(['resetImplementations'])
      ->getMock();

    // Ensure we reset implementations when settings a new modules list.
    $module_handler->expects($this->once())->method('resetImplementations');

    // @todo this should probably fail since its a module not a profile.
    $module_handler->addProfile('module_handler_test', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test');
    $this->assertTrue($module_handler->moduleExists('module_handler_test'));
  }

  /**
   * Tests module exists returns correct module status.
   *
   * @covers ::moduleExists
   */
  public function testModuleExists(): void {
    $module_handler = $this->getModuleHandler();
    $this->assertTrue($module_handler->moduleExists('module_handler_test'));
    $this->assertFalse($module_handler->moduleExists('module_handler_test_added'));
  }

  /**
   * @covers ::loadAllIncludes
   */
  public function testLoadAllIncludes(): void {
    $this->assertTrue(TRUE);
    $module_handler = $this->getMockBuilder(ModuleHandler::class)
      ->setConstructorArgs([
        $this->root,
        [
          'module_handler_test' => [
            'type' => 'module',
            'pathname' => 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test/module_handler_test.info.yml',
            'filename' => 'module_handler_test.module',
          ],
        ], $this->eventDispatcher, [],
      ])
      ->onlyMethods(['loadInclude'])
      ->getMock();

    // Ensure we reset implementations when settings a new modules list.
    $module_handler->expects($this->once())->method('loadInclude');
    $module_handler->loadAllIncludes('hook');
  }

  /**
   * Tests loadInclude().
   *
   * Note we load code, so isolate the test.
   *
   * @covers ::loadInclude
   * @runInSeparateProcess
   * @preserveGlobalState disabled
   */
  public function testLoadInclude(): void {
    $module_handler = $this->getModuleHandler();
    // Include exists.
    $this->assertEquals(__DIR__ . '/modules/module_handler_test/hook_include.inc', $module_handler->loadInclude('module_handler_test', 'inc', 'hook_include'));
    $this->assertTrue(function_exists('module_handler_test_hook_include'));
    // Include doesn't exist.
    $this->assertFalse($module_handler->loadInclude('module_handler_test', 'install'));
  }

  /**
   * Tests invoke methods when module is enabled.
   *
   * @covers ::invoke
   */
  public function testInvokeModuleEnabled(): void {
    $module_handler = $this->getModuleHandler();
    $module_handler->loadAll();
    $this->assertTrue($module_handler->invoke('module_handler_test', 'hook', [TRUE]), 'Installed module runs hook.');
    $this->assertFalse($module_handler->invoke('module_handler_test', 'hook', [FALSE]), 'Installed module runs hook.');
    $this->assertNull($module_handler->invoke('module_handler_test_fake', 'hook', [FALSE]), 'Installed module runs hook.');
  }

  /**
   * Tests implementations methods when module is enabled.
   *
   * @covers ::hasImplementations
   * @covers ::loadAllIncludes
   */
  public function testImplementsHookModuleEnabled(): void {
    $implementations['hook'][ProceduralCall::class]['module_handler_test_hook'] = 'module_handler_test';
    $module_handler = $this->getModuleHandler($implementations);

    $this->assertTrue($module_handler->hasImplementations('hook', 'module_handler_test'), 'Installed module implementation found.');

    $module_handler->addModule('module_handler_test_added', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test_added');
    $this->assertTrue($module_handler->hasImplementations('hook', 'module_handler_test_added'), 'Runtime added module with implementation in include found.');

    $module_handler->addModule('module_handler_test_no_hook', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test_no_hook');
    $this->assertFalse($module_handler->hasImplementations('hook', 'module_handler_test_no_hook'), 'Missing implementation not found.');
  }

  /**
   * Tests invoke all.
   *
   * @covers ::invokeAll
   */
  public function testInvokeAll(): void {
    $module_handler = $this->getModuleHandler();
    $module_handler->addModule('module_handler_test_all1', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test_all1');
    $module_handler->addModule('module_handler_test_all2', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test_all2');
    $this->assertEquals([TRUE, TRUE, TRUE], $module_handler->invokeAll('hook', [TRUE]));
  }

  /**
   * Tests hasImplementations.
   *
   * @covers ::getHookListeners
   * @covers ::hasImplementations
   */
  public function testHasImplementations(): void {
    $c = new class {

      function some_method(): void {

      }

    };
    $implementations['some_hook'][get_class($c)]['some_method'] = 'some_module';
    $module_handler = $this->getModuleHandler($implementations);
    $module_handler->setModuleList(['some_module' => TRUE]);
    $r = new \ReflectionObject($module_handler);

    // Set up some synthetic results.
    $this->eventDispatcher
      ->expects($this->once())
      ->method('getListeners')
      ->with('drupal_hook.some_hook')
      ->willReturn([
        [$c, 'some_method'],
      ]);
    $this->assertNotEmpty($module_handler->hasImplementations('some_hook'));

    $listeners = $r->getProperty('invokeMap')->getValue($module_handler)['some_hook']['some_module'];
    // Anonymous class doesn't work with assertSame() so assert it piecemeal.
    $this->assertSame(count($listeners), 1);
    foreach ($listeners as $listener) {
      $this->assertSame(get_class($c), get_class($listener[0]));
      $this->assertSame('some_method', $listener[1]);
    }
  }

  /**
   * @covers ::getModuleDirectories
   */
  public function testGetModuleDirectories(): void {
    $module_handler = $this->getModuleHandler();
    $module_handler->setModuleList([]);
    $module_handler->addModule('node', 'core/modules/node');
    $this->assertEquals(['node' => $this->root . '/core/modules/node'], $module_handler->getModuleDirectories());
  }

  /**
   * @covers ::getHookListeners
   */
  public function testGroupIncludes(): void {
    self::setupGroupIncludes();
    $moduleHandler = new ModuleHandler('', [], new EventDispatcher(), [], self::GROUP_INCLUDES);
    $this->assertFalse(function_exists('_test_module_helper'));
    $moduleHandler->invokeAll('token_info');
    $this->assertTrue(function_exists('_test_module_helper'));
  }

}
