<?php

declare(strict_types=1);

namespace App\Services\Agents;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The public pages written in Markdown (resources/markdown): the single source for both the HTML a
 * browser sees and the Markdown an agent asks for. Placeholders are filled from config, so the operator
 * and contact stay right per deployment.
 */
final class MarkdownPages
{
    /** @var array<string, string> page => title */
    public const array PAGES = [
        'home' => 'Sawirpixel',
        'terms' => 'Terms of service',
        'privacy' => 'Privacy policy',
    ];

    /**
     * The page as a complete Markdown document, with its title.
     */
    public function markdown(string $page): string
    {
        $title = $page === 'home' ? Config::string('app.name') : (self::PAGES[$page] ?? throw new InvalidArgumentException(sprintf('No Markdown page [%s].', $page)));

        return '# '.$title."\n\n".$this->body($page);
    }

    /**
     * The page's body (without its title) as HTML, for the web page that shows it.
     */
    public function html(string $page): string
    {
        return Str::markdown($this->body($page), ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    private function body(string $page): string
    {
        throw_unless(array_key_exists($page, self::PAGES), InvalidArgumentException::class, sprintf('No Markdown page [%s].', $page));

        $contactEmail = Config::get('app.legal.contact_email');
        $operator = Config::string('app.legal.operator');

        return strtr((string) file_get_contents(resource_path(sprintf('markdown/%s.md', $page))), [
            '{app}' => Config::string('app.name'),
            '{operator}' => $operator,
            '{contact}' => is_string($contactEmail) && $contactEmail !== '' ? sprintf('[%1$s](mailto:%1$s)', $contactEmail) : sprintf('contact %s.', $operator),
        ]);
    }
}
