<?php

declare(strict_types=1);

/**
 * This file is part of the MultiFlexi package
 *
 * https://multiflexi.eu/
 *
 * (c) Vítězslav Dvořák <http://vitexsoftware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MultiFlexi\Test;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the single-instance flock guard added to daemon.php.
 *
 * All tests are pure / offline — no database or external process required.
 */
class DaemonGuardTest extends TestCase
{
    /**
     * Verify daemon.php contains a flock-based single-instance guard.
     * This test acts as a regression guard so the protection cannot be
     * silently removed during a refactor.
     */
    public function testDaemonHasSingleInstanceFlockGuard(): void
    {
        $daemonPath = \dirname(__DIR__, 2).'/src/daemon.php';
        $this->assertFileExists($daemonPath, 'daemon.php must exist');

        $source = file_get_contents($daemonPath);
        $this->assertStringContainsString('flock(', $source, 'daemon.php must use flock() for single-instance protection');
        $this->assertStringContainsString('LOCK_EX', $source, 'daemon.php must request an exclusive lock');
        $this->assertStringContainsString('LOCK_NB', $source, 'daemon.php must use a non-blocking lock so a second instance exits immediately');
    }

    /**
     * Verify that a second flock() call on the same file fails while the
     * first handle holds the exclusive lock.
     */
    public function testFlockPreventsDoubleAcquisition(): void
    {
        $pidFile = sys_get_temp_dir().'/test-scheduler-lock-'.uniqid().'.pid';
        $fp1 = fopen($pidFile, 'c');
        $this->assertNotFalse($fp1, 'Should be able to open pidfile');

        $acquired = flock($fp1, \LOCK_EX | \LOCK_NB);
        $this->assertTrue($acquired, 'First instance should acquire the lock');

        $fp2 = fopen($pidFile, 'c');
        $this->assertNotFalse($fp2, 'Should be able to open pidfile a second time');

        $blocked = flock($fp2, \LOCK_EX | \LOCK_NB);
        $this->assertFalse($blocked, 'Second instance must not acquire the lock while first holds it');

        flock($fp1, \LOCK_UN);
        fclose($fp1);
        fclose($fp2);
        @unlink($pidFile);
    }

    /**
     * Verify that the lock becomes available once the previous holder releases it
     * (simulates daemon process exit).
     */
    public function testFlockReleasedOnClose(): void
    {
        $pidFile = sys_get_temp_dir().'/test-scheduler-lock-'.uniqid().'.pid';
        $fp1 = fopen($pidFile, 'c');
        flock($fp1, \LOCK_EX | \LOCK_NB);
        flock($fp1, \LOCK_UN);
        fclose($fp1);

        $fp2 = fopen($pidFile, 'c');
        $reacquired = flock($fp2, \LOCK_EX | \LOCK_NB);
        $this->assertTrue($reacquired, 'Lock should be available after previous holder released it');

        flock($fp2, \LOCK_UN);
        fclose($fp2);
        @unlink($pidFile);
    }
}
