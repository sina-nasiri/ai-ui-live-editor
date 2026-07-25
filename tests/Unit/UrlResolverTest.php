<?php

namespace Tests\Unit;

use App\Support\UrlResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlResolverTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_it_resolves_against_the_base(string $input, string $expected): void
    {
        $resolver = new UrlResolver('https://example.com/blog/2026/post.html');

        $this->assertSame($expected, $resolver->resolve($input));
    }

    public static function cases(): array
    {
        return [
            'root relative' => ['/a.png', 'https://example.com/a.png'],
            'path relative' => ['a.png', 'https://example.com/blog/2026/a.png'],
            'parent' => ['../a.png', 'https://example.com/blog/a.png'],
            'two parents' => ['../../a.png', 'https://example.com/a.png'],
            'current dir' => ['./a.png', 'https://example.com/blog/2026/a.png'],
            'protocol relative' => ['//cdn.test/a.png', 'https://cdn.test/a.png'],
            'already absolute' => ['http://other.test/a.png', 'http://other.test/a.png'],
            'query preserved' => ['/a.png?v=2', 'https://example.com/a.png?v=2'],
            'fragment preserved' => ['/a.html#top', 'https://example.com/a.html#top'],

            // These are not network references and must survive untouched.
            'anchor only' => ['#top', '#top'],
            'data uri' => ['data:image/png;base64,AAAA', 'data:image/png;base64,AAAA'],
            'mailto' => ['mailto:a@b.test', 'mailto:a@b.test'],
            'tel' => ['tel:+1234', 'tel:+1234'],
        ];
    }

    public function test_a_port_on_the_base_is_carried_through(): void
    {
        $resolver = new UrlResolver('http://localhost:8000/app/index.html');

        $this->assertSame('http://localhost:8000/a.png', $resolver->resolve('/a.png'));
        $this->assertSame('http://localhost:8000/app/a.png', $resolver->resolve('a.png'));
    }

    public function test_traversal_above_the_root_is_clamped(): void
    {
        $resolver = new UrlResolver('https://example.com/a.html');

        $this->assertSame('https://example.com/x.png', $resolver->resolve('../../../x.png'));
    }
}
