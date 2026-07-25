<?php

namespace App\Services\Ai;

/**
 * Prompts and response schemas for each kind of AI request.
 *
 * The important design decision lives here: for ordinary edits the model does
 * not rewrite HTML. It returns a small list of CSS declarations keyed to
 * element ids the browser assigned. That makes edits an order of magnitude
 * cheaper and faster, means the model physically cannot delete a paragraph of
 * someone's copy, gives undo for free (drop the patch), and produces real CSS
 * to hand a developer instead of inline-style soup.
 *
 * Rewriting whole HTML is still available as "restructure" mode for the rarer
 * case where the markup itself genuinely has to change.
 */
class Prompts
{
    /** A CSS declaration pair, shaped so a strict schema can validate it. */
    private const DECLARATION = [
        'type' => 'object',
        'properties' => [
            'property' => ['type' => 'string', 'description' => 'CSS property name in kebab-case, e.g. font-size'],
            'value' => ['type' => 'string', 'description' => 'CSS value, e.g. 1.5rem'],
        ],
        'required' => ['property', 'value'],
        'additionalProperties' => false,
    ];

    // ---------------------------------------------------------------- edit

    public static function editSystem(): string
    {
        return <<<'PROMPT'
        You are a senior UI designer editing a live web page.

        You will be given an outline of the selected element and its
        descendants. Every node has an id like [e7]. You will also get the
        page's own design tokens — its palette, type scale and spacing.

        Return a list of CSS declarations to apply, keyed by those ids.

        Rules:
        - Only reference ids that appear in the outline.
        - Change as little as possible. Do not restyle nodes the request did
          not ask about.
        - Match the page's existing tokens unless the request asks you to
          break from them. An edit that ignores the site's palette and type
          scale looks pasted on.
        - Use the "text" field only when the request is about wording. Leave
          it as an empty string otherwise. Never rewrite copy uninvited.
        - Prefer relative units and existing CSS custom properties where the
          page already uses them.
        - Do not emit `!important`; the patch stylesheet already wins.
        - Keep the summary to one sentence about what you changed and why.
        PROMPT;
    }

    /**
     * @return array<string,mixed>
     */
    public static function editSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string', 'description' => 'One sentence describing the change.'],
                'changes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'description' => 'Element id from the outline, e.g. e7'],
                            'declarations' => ['type' => 'array', 'items' => self::DECLARATION],
                            'text' => [
                                'type' => 'string',
                                'description' => 'Replacement text, or an empty string to leave the text alone.',
                            ],
                        ],
                        'required' => ['id', 'declarations', 'text'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['summary', 'changes'],
            'additionalProperties' => false,
        ];
    }

    // ------------------------------------------------------------ variants

    public static function variantsSystem(int $count): string
    {
        return self::editSystem()."\n\n"
            ."Produce {$count} genuinely different directions, not {$count} shades of "
            .'the same idea. Give each a short label a stakeholder would '
            .'understand and one line on the design thinking behind it.';
    }

    /**
     * @return array<string,mixed>
     */
    public static function variantsSchema(): array
    {
        $edit = self::editSchema();

        return [
            'type' => 'object',
            'properties' => [
                'variants' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'label' => ['type' => 'string', 'description' => 'Short name, e.g. "Bolder headline"'],
                            'rationale' => ['type' => 'string', 'description' => 'One line on the design thinking.'],
                            'changes' => $edit['properties']['changes'],
                        ],
                        'required' => ['label', 'rationale', 'changes'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['variants'],
            'additionalProperties' => false,
        ];
    }

    // --------------------------------------------------------- restructure

    public static function restructureSystem(): string
    {
        return <<<'PROMPT'
        You are a senior front-end engineer restructuring one element of a page.

        Return the complete replacement HTML for the element you are given.

        Rules:
        - Return the whole element, including its outer tag. Never truncate.
        - Preserve every piece of visible text unless the request asks you to
          change it. Losing someone's copy is worse than an ugly layout.
        - Keep existing class names and attributes; add to them rather than
          replacing them.
        - Style with inline `style` attributes so the result works without the
          page's own stylesheet.
        - No <script>, no event handler attributes, no external resources that
          were not already there.
        PROMPT;
    }

    /**
     * @return array<string,mixed>
     */
    public static function restructureSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string', 'description' => 'One sentence describing the change.'],
                'html' => ['type' => 'string', 'description' => 'The complete replacement HTML for the element.'],
            ],
            'required' => ['summary', 'html'],
            'additionalProperties' => false,
        ];
    }

    // ------------------------------------------------------------ critique

    public static function critiqueSystem(): string
    {
        return <<<'PROMPT'
        You are a UX reviewer giving a design critique. You are not editing
        anything — you are writing findings a team can act on.

        Review the element outline for: visual hierarchy, contrast and
        legibility, spacing rhythm and alignment, copy clarity, call-to-action
        prominence, scannability, and anything that would trip up a first-time
        visitor.

        Rules:
        - Be specific. "Improve hierarchy" is useless; "the headline and body
          copy are both 16px, so nothing leads the eye" is a finding.
        - Reference the element id you are talking about.
        - Rank by how much the fix would matter, not how easy it is.
        - Say so plainly if a section is already working well. Do not invent
          problems to fill a quota.
        - At most eight findings.
        PROMPT;
    }

    /**
     * @return array<string,mixed>
     */
    public static function critiqueSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string', 'description' => 'Two sentences on the overall impression.'],
                'findings' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'description' => 'Element id from the outline, e.g. e7'],
                            'title' => ['type' => 'string', 'description' => 'The finding in one short line.'],
                            'severity' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                            'recommendation' => ['type' => 'string', 'description' => 'What to do about it.'],
                        ],
                        'required' => ['id', 'title', 'severity', 'recommendation'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['summary', 'findings'],
            'additionalProperties' => false,
        ];
    }
}
