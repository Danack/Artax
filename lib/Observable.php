<?php

namespace Artax;

interface Observable {

    /**
     * Attach an array of event observations
     *
     * @param array $listeners A key-value array mapping event names to callable listeners
     */
    public function addObservation(array $listeners);

    /**
     * Cancel the specified Observation
     *
     * @param Observation $observation
     */
    public function removeObservation(Observation $observation);

    /**
     * Clear all existing observations of this Observable
     */
    public function removeAllObservations();
}
