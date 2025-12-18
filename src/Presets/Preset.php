<?php namespace EvolutionCMS\Installer\Presets;

abstract class Preset
{
    /**
     * Install the preset.
     *
     * @param string $name
     * @param array $options
     * @return void
     */
    abstract public function install(string $name, array $options): void;

    /**
     * Get the preset name.
     *
     * @return string
     */
    abstract public function name(): string;

    /**
     * Get the preset description.
     *
     * @return string
     */
    abstract public function description(): string;
}

