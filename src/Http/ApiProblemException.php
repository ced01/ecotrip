<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class ApiProblemException extends HttpException
{
    /** @param list<array{path:string,message:string}> $violations */
    public function __construct(int $status, public readonly string $problemCode, array $violations = [], array $headers = [])
    {
        $this->violations = $violations;
        parent::__construct($status, '', null, $headers);
    }

    /** @var list<array{path:string,message:string}> */
    public readonly array $violations;
}
