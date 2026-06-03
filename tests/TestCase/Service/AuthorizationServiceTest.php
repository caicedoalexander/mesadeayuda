<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\RoleConstants;
use App\Service\AuthorizationService;
use Authentication\Identity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthorizationServiceTest extends TestCase
{
    private AuthorizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AuthorizationService();
    }

    public function testAssignmentDisabledWhenUnauthenticated(): void
    {
        // Fail-closed: a null identity must never be allowed to assign tickets.
        self::assertTrue($this->service->isAssignmentDisabled(null));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function staffRoleProvider(): array
    {
        return [
            'admin' => [RoleConstants::ROLE_ADMIN],
            'asesor_tic' => [RoleConstants::ROLE_ASESOR_TIC],
        ];
    }

    #[DataProvider('staffRoleProvider')]
    public function testAssignmentEnabledForStaffRoles(string $role): void
    {
        $identity = new Identity(['role' => $role]);
        self::assertFalse($this->service->isAssignmentDisabled($identity));
    }

    public function testAssignmentDisabledForExternalRole(): void
    {
        $identity = new Identity(['role' => RoleConstants::ROLE_EXTERNAL]);
        self::assertTrue($this->service->isAssignmentDisabled($identity));
    }

    public function testAssignmentDisabledForUnknownRole(): void
    {
        $identity = new Identity(['role' => 'some_unknown_role']);
        self::assertTrue($this->service->isAssignmentDisabled($identity));
    }

    public function testAssignmentDisabledWhenRoleIsMissing(): void
    {
        // Identity without a 'role' key: get('role') yields null, which is not
        // a staff role, so assignment stays disabled.
        $identity = new Identity(['id' => 1]);
        self::assertTrue($this->service->isAssignmentDisabled($identity));
    }
}
