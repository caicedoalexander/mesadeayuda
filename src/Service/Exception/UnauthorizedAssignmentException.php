<?php
declare(strict_types=1);

namespace App\Service\Exception;

use DomainException;

/**
 * Thrown when an attempt is made to assign a ticket without
 * the required authorization, either because the actor lacks
 * the role or because the target user/ticket cannot accept it.
 */
class UnauthorizedAssignmentException extends DomainException
{
}
