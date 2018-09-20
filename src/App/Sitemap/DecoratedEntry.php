<?php

namespace App\Sitemap;

use Dpn\XmlSitemapBundle\Sitemap\Entry;

class DecoratedEntry extends Entry
{
    /**
     * @var string
     */
    protected $description;

    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }
}
