<?php

namespace Tests\Unit;

use App\Support\CssGuard;
use PHPUnit\Framework\TestCase;

class CssGuardTest extends TestCase
{
    public function test_ordinary_declarations_pass_through(): void
    {
        $result = CssGuard::filter([
            ['property' => 'font-size', 'value' => '2rem'],
            ['property' => '--brand', 'value' => '#4f46e5'],
        ]);

        $this->assertSame([
            ['property' => 'font-size', 'value' => '2rem'],
            ['property' => '--brand', 'value' => '#4f46e5'],
        ], $result);
    }

    /**
     * A brace or semicolon in a value would close the rule early and let one
     * declaration write arbitrary CSS anywhere in the patch stylesheet.
     */
    public function test_values_that_could_escape_the_rule_are_dropped(): void
    {
        $result = CssGuard::filter([
            ['property' => 'color', 'value' => 'red} body{display:none'],
            ['property' => 'color', 'value' => 'red; position:fixed'],
        ]);

        $this->assertSame([], $result);
    }

    public function test_script_bearing_values_are_dropped(): void
    {
        $result = CssGuard::filter([
            ['property' => 'background', 'value' => 'url(javascript:alert(1))'],
            ['property' => 'width', 'value' => 'expression(alert(1))'],
        ]);

        $this->assertSame([], $result);
    }

    public function test_malformed_property_names_are_dropped(): void
    {
        $result = CssGuard::filter([
            ['property' => 'font size', 'value' => '2rem'],
            ['property' => '', 'value' => '2rem'],
            ['property' => 'a}b', 'value' => '2rem'],
        ]);

        $this->assertSame([], $result);
    }

    /**
     * The patch stylesheet already sits last in the cascade, so !important
     * only makes the result harder for the user to override later.
     */
    public function test_important_is_stripped_rather_than_rejected(): void
    {
        $result = CssGuard::filter([['property' => 'color', 'value' => 'red !important']]);

        $this->assertSame([['property' => 'color', 'value' => 'red']], $result);
    }

    public function test_absurdly_long_values_are_dropped(): void
    {
        $result = CssGuard::filter([['property' => 'content', 'value' => str_repeat('a', 500)]]);

        $this->assertSame([], $result);
    }
}
