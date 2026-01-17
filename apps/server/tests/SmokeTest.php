<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SmokeTest extends WebTestCase
{
    public function testHealthEndpoint(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/health');

        $this->assertResponseIsSuccessful();
    }

    public function testLoginPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
    }

    public function testRegisterPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/register');

        $this->assertResponseIsSuccessful();
    }

    public function testDashboardRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseRedirects('/login');
    }

    public function testProjectsRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/projects');

        $this->assertResponseRedirects('/login');
    }

    public function testIssuesRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/issues');

        $this->assertResponseRedirects('/login');
    }
}
