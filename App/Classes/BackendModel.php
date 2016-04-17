<?php

namespace App\Submodules\ToolsLaravelMicroservice\App\Classes;

use ArrayAccess;
use App\Submodules\ToolsLaravelMicroservice\App\Classes\BackendRequest;

/*
    Provides a base interface for retrieving and updating models from
    our backend microservices
*/

abstract class BackendModel implements ArrayAccess
{
    /**
     * Connection to the service being queried
     *
     * @var BackendRequest
     */
    protected $connection;

    /**
     * Service from which we want to retrieve the model
     *
     * @var string
     */
    protected $service;

    /**
     * Name of the model being queried, e.g., 'device', or 'location'
     *
     * @var string
     */
    protected $modelName;

    /**
     * Dataset of the retrieved model
     *
     * @var string
     */
    protected $modelData;

    /**
     * Instantiate the model with a connection to the backend
     *
     * @var string
     */
    public function __construct()
    {
        $this->connection = new BackendRequest($this->service);
    }

    protected function pipe(string $method, string $path, array $data)
    {
        $response = $this->connection->$method($path, $data);

        if ($this->connection->code() != 200) {
            throw new \Exception('Insert proper error handling here. :D');
        }

        return $response;
    }

    public function load(array $data)
    {
        $this->modelData = $this->pipe('get', $this->modelName, $data);

        return $this->modelData;
    }

    public function fresh(array $data)
    {
        return $this->load($data);
    }

    public function create(array $data)
    {
        $this->modelData = $this->pipe('put', $this->modelName, $data);

        return $this->modelData;
    }

    public function update(array $data)
    {
        $this->modelData = $this->pipe('patch', $this->modelName, $data);

        return $this->modelData;
    }

    public function delete(array $data)
    {
        $this->modelData = $this->pipe('delete', $this->modelName, $data);

        return $this->modelData;
    }

    /**
     * Determine if the given attribute exists.
     *
     * @param  mixed  $offset
     * @return bool
     */
    public function offsetExists($offset)
    {
        return isset($this->$offset);
    }

    /**
     * Get the value for a given offset.
     *
     * @param  mixed  $offset
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return $this->$offset;
    }

    /**
     * Set the value for a given offset.
     *
     * @param  mixed  $offset
     * @param  mixed  $value
     * @return void
     */
    public function offsetSet($offset, $value)
    {
        $this->$offset = $value;
    }

    /**
     * Unset the value for a given offset.
     *
     * @param  mixed  $offset
     * @return void
     */
    public function offsetUnset($offset)
    {
        unset($this->$offset);
    }
}
