<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\AuditLogRepository;
use App\Repository\PendingRegistrationRepository;
use App\Repository\UserRepository;
use App\Service\AdminUserService;
use App\Service\AuthService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AdminUserService::class)]
final class AdminUserServiceTest extends TestCase
{
    private function service(
        UserRepository $users,
        AuditLogRepository $audit,
        ?AuthService $auth = null,
    ): AdminUserService {
        return new AdminUserService(
            $users,
            $audit,
            $auth ?? $this->createStub(AuthService::class),
            $this->createStub(PendingRegistrationRepository::class),
        );
    }

    public function testToggleAdminFlipsTheFlagAndRecordsAudit(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 5, 'is_admin' => 0]);
        $users->expects($this->once())->method('updateAdmin')->with(5, true);

        $audit = $this->createMock(AuditLogRepository::class);
        $audit->expects($this->once())
              ->method('record')
              ->with(1, 'toggle_admin', 5, ['from' => false, 'to' => true]);

        $this->service($users, $audit)->toggleAdmin(actorId: 1, targetId: 5);
    }

    public function testToggleAdminDemotesAnExistingAdmin(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 5, 'is_admin' => 1]);
        $users->expects($this->once())->method('updateAdmin')->with(5, false);

        $audit = $this->createMock(AuditLogRepository::class);
        $audit->expects($this->once())
              ->method('record')
              ->with(1, 'toggle_admin', 5, ['from' => true, 'to' => false]);

        $this->service($users, $audit)->toggleAdmin(1, 5);
    }

    public function testActorCannotChangeOwnAdminFlag(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->never())->method('updateAdmin');

        $audit = $this->createMock(AuditLogRepository::class);
        $audit->expects($this->never())->method('record');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('your own admin flag');

        $this->service($users, $audit)->toggleAdmin(actorId: 1, targetId: 1);
    }

    public function testToggleAdminFailsWhenUserMissing(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(null);
        $users->expects($this->never())->method('updateAdmin');

        $audit = $this->createStub(AuditLogRepository::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('User not found');

        $this->service($users, $audit)->toggleAdmin(1, 99);
    }

    public function testSoftDeleteMarksDeletedAndAudits(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 5, 'username' => 'bob']);
        $users->expects($this->once())->method('softDelete')->with(5);

        $audit = $this->createMock(AuditLogRepository::class);
        $audit->expects($this->once())
              ->method('record')
              ->with(1, 'soft_delete', 5, ['username' => 'bob']);

        $this->service($users, $audit)->softDelete(1, 5);
    }

    public function testActorCannotSoftDeleteSelf(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->never())->method('softDelete');

        $audit = $this->createMock(AuditLogRepository::class);
        $audit->expects($this->never())->method('record');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('delete your own');

        $this->service($users, $audit)->softDelete(1, 1);
    }

    public function testRestoreClearsDeletionAndAudits(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findByIdIncludingDeleted')
              ->willReturn(['id' => 5, 'username' => 'bob', 'deleted_at' => '2026-06-04 10:00:00']);
        $users->expects($this->once())->method('restore')->with(5);

        $audit = $this->createMock(AuditLogRepository::class);
        $audit->expects($this->once())
              ->method('record')
              ->with(1, 'restore', 5, ['username' => 'bob']);

        $this->service($users, $audit)->restore(1, 5);
    }

    public function testRestoreFailsWhenUserMissing(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findByIdIncludingDeleted')->willReturn(null);
        $users->expects($this->never())->method('restore');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('User not found');

        $this->service($users, $this->createStub(AuditLogRepository::class))->restore(1, 99);
    }

    public function testRestoreFailsWhenUserNotDeleted(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findByIdIncludingDeleted')
              ->willReturn(['id' => 5, 'username' => 'bob', 'deleted_at' => null]);
        $users->expects($this->never())->method('restore');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not deleted');

        $this->service($users, $this->createStub(AuditLogRepository::class))->restore(1, 5);
    }

    public function testResendVerificationDelegatesToAuthResendCodeAndAudits(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 5, 'username' => 'carol']);

        $audit = $this->createMock(AuditLogRepository::class);
        $audit->expects($this->once())
              ->method('record')
              ->with(1, 'resend_verification', 5);

        // Admin resend goes through resendCode (no captcha, but still capped),
        // not the public login() flow.
        $auth = $this->createMock(AuthService::class);
        $auth->expects($this->once())
             ->method('resendCode')
             ->with(5);

        $this->service($users, $audit, $auth)
             ->resendVerification(actorId: 1, targetId: 5, ip: '10.0.0.5');
    }

    public function testResendVerificationFailsWhenUserMissing(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn(null);

        $audit = $this->createStub(AuditLogRepository::class);
        $auth = $this->createMock(AuthService::class);
        $auth->expects($this->never())->method('login');

        $this->expectException(RuntimeException::class);

        $this->service($users, $audit, $auth)->resendVerification(1, 99, '127.0.0.1');
    }

    public function testStatsComputesPeriodOverPeriodTrends(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('countLive')->willReturn(42);
        $users->method('countWithApiToken')->willReturn(30);
        // Signups doubled (5 → 10): +100%, up.
        $users->method('countCreatedSince')->willReturn(10);
        $users->method('countCreatedBetween')->willReturn(5);
        // Activity halved (8 → 4): -50%, down.
        $users->method('countActiveSince')->willReturn(4);
        $users->method('countActiveBetween')->willReturn(8);

        $pending = $this->createStub(PendingRegistrationRepository::class);
        $pending->method('countActive')->willReturn(3);

        $stats = $this->statsService($users, $pending)->stats(30);

        $this->assertSame(30, $stats['window_days']);
        $this->assertSame(42, $stats['total']);
        $this->assertSame(3, $stats['pending']);

        $this->assertSame(100, $stats['signups']['pct']);
        $this->assertSame('up', $stats['signups']['direction']);

        $this->assertSame(-50, $stats['active']['pct']);
        $this->assertSame('down', $stats['active']['direction']);
    }

    public function testStatsReportsNullPctWhenPriorWindowWasEmpty(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('countCreatedSince')->willReturn(7);
        $users->method('countCreatedBetween')->willReturn(0);
        $users->method('countActiveSince')->willReturn(0);
        $users->method('countActiveBetween')->willReturn(0);

        $pending = $this->createStub(PendingRegistrationRepository::class);

        $stats = $this->statsService($users, $pending)->stats(7);

        // No baseline → null pct (the view renders "New"/"No baseline").
        $this->assertNull($stats['signups']['pct']);
        $this->assertSame('up', $stats['signups']['direction']);
        $this->assertNull($stats['active']['pct']);
        $this->assertSame('flat', $stats['active']['direction']);
    }

    public function testStatsRejectsUnknownWindowAndFallsBackTo30(): void
    {
        $users = $this->createStub(UserRepository::class);
        $pending = $this->createStub(PendingRegistrationRepository::class);

        $this->assertSame(30, $this->statsService($users, $pending)->stats(999)['window_days']);
    }

    private function statsService(
        UserRepository $users,
        PendingRegistrationRepository $pending,
    ): AdminUserService {
        return new AdminUserService(
            $users,
            $this->createStub(AuditLogRepository::class),
            $this->createStub(AuthService::class),
            $pending,
        );
    }
}
