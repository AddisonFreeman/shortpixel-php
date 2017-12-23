<?php

namespace ShortPixel;

abstract class OptimizedItemsProducer 
{
	private $result;

    function __construct($result)
    {
        $this->result = $result;
    }

	public function aprint() {
		return var_dump($this->result);
	}
	
}