<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;

class SmokeTest extends WebTestCase
{
    use HasBrowser;

    public function testHealthEndpoint(): void
    {
        $this->browser()
            ->visit('/api/v1/health')
            ->assertSuccessful();
    }

    public function testLoginPageLoads(): void
    {
        $this->browser()
            ->visit('/login')
            ->assertSuccessful();
    }

    public function testRegisterPageLoads(): void
    {
        $this->browser()
            ->visit('/register')
            ->assertSuccessful();
    }

    public function testDashboardRequiresAuth(): void
    {
        $this->browser()
            ->interceptRedirects()
            ->visit('/')
            ->assertRedirectedTo('/login');
    }

    public function testProjectsRequiresAuth(): void
    {
        $this->browser()
            ->interceptRedirects()
            ->visit('/projects')
            ->assertRedirectedTo('/login');
    }

    public function testIssuesRequiresAuth(): void
    {
        $this->browser()
            ->interceptRedirects()
            ->visit('/issues')
            ->assertRedirectedTo('/login');
    }
}
