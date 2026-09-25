<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Server;

use Closure;
use DOMDocument;
use DOMElement;

/**
 * One WebDAV property in a multistatus response.
 *
 * A property is either found (and goes into the 200 propstat, with a text value,
 * a structured value, or no value at all when the client asked for names only)
 * or not found (and goes into the 404 propstat, so the client learns the server
 * does not have it instead of learning nothing).
 */
final class DavProperty
{
    /**
     * @param Closure(DOMDocument, DOMElement): void|null $builder
     */
    private function __construct(
        public readonly string $namespace,
        public readonly string $name,
        public readonly bool $found,
        public readonly ?string $text = null,
        public readonly ?Closure $builder = null,
    ) {
    }

    public static function text(string $namespace, string $name, string $value): self
    {
        return new self($namespace, $name, true, $value);
    }

    /**
     * @param Closure(DOMDocument, DOMElement): void $builder Fills the property element with child nodes.
     */
    public static function structured(string $namespace, string $name, Closure $builder): self
    {
        return new self($namespace, $name, true, null, $builder);
    }

    /** A property that exists but has no content (e.g. resourcetype of a plain resource). */
    public static function emptyValue(string $namespace, string $name): self
    {
        return new self($namespace, $name, true);
    }

    public static function notFound(string $namespace, string $name): self
    {
        return new self($namespace, $name, false);
    }

    /** The same property stripped of its value — what PROPFIND/propname answers with. */
    public function withoutValue(): self
    {
        return new self($this->namespace, $this->name, $this->found);
    }
}
