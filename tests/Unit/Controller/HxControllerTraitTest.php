<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Trait\HxControllerTrait;
use App\Entity\Channel;
use App\Repository\ChannelRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class HxControllerTraitTest extends TestCase
{
    private object $controller;

    protected function setUp(): void
    {
        $this->controller = new class extends AbstractController {
            use HxControllerTrait;

            public function testRedirectOrHxRedirect(Request $request, string $url): Response
            {
                return $this->redirectOrHxRedirect($request, $url);
            }

            public function testFindActiveChannel(Request $request, ChannelRepository $repo): ?Channel
            {
                return $this->findActiveChannelFromHxRequest($request, $repo);
            }
        };
    }

    #[Test]
    public function redirectOrHxRedirectReturns204WithHeaderWhenHxRequest(): void
    {
        $request = new Request();
        $request->headers->set('HX-Request', 'true');

        $response = $this->controller->testRedirectOrHxRedirect($request, '/channels/dev');

        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        $this->assertSame('/channels/dev', $response->headers->get('HX-Redirect'));
    }

    #[Test]
    public function redirectOrHxRedirectReturnsStandardRedirectWhenNotHxRequest(): void
    {
        $request = new Request();

        $response = $this->controller->testRedirectOrHxRedirect($request, '/channels/dev');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/channels/dev', $response->headers->get('Location'));
    }

    #[Test]
    public function findActiveChannelFromHxRequestReturnsChannelWhenUrlMatches(): void
    {
        $channel = new Channel();
        $channel->setSlug('general');

        $repo = $this->createMock(ChannelRepository::class);
        $repo->expects($this->once())->method('findOneBy')->with(['slug' => 'general'])->willReturn($channel);

        $request = new Request();
        $request->headers->set('HX-Current-URL', 'http://localhost/channels/general');

        $result = $this->controller->testFindActiveChannel($request, $repo);
        $this->assertSame($channel, $result);
    }

    #[Test]
    public function findActiveChannelFromHxRequestReturnsNullWhenNoMatch(): void
    {
        $repo = $this->createStub(ChannelRepository::class);

        $request1 = new Request();
        $this->assertNull($this->controller->testFindActiveChannel($request1, $repo));

        $request2 = new Request();
        $request2->headers->set('HX-Current-URL', 'http://localhost/dashboard');
        $this->assertNull($this->controller->testFindActiveChannel($request2, $repo));
    }
}
