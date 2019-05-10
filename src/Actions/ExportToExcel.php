<?php

namespace Maatwebsite\LaravelNovaExcel\Actions;

use Laravel\Nova\Nova;
use Laravel\Nova\Resource;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\Gravatar;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithMapping;
use Laravel\Nova\Http\Requests\ActionRequest;
use Maatwebsite\LaravelNovaExcel\Concerns\Only;
use Maatwebsite\LaravelNovaExcel\Concerns\Except;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\LaravelNovaExcel\Concerns\WithDisk;
use Maatwebsite\LaravelNovaExcel\Concerns\WithFilename;
use Maatwebsite\LaravelNovaExcel\Concerns\WithHeadings;
use Maatwebsite\LaravelNovaExcel\Concerns\WithChunkCount;
use Maatwebsite\LaravelNovaExcel\Concerns\WithWriterType;
use Maatwebsite\LaravelNovaExcel\Requests\SerializedRequest;
use Maatwebsite\LaravelNovaExcel\Interactions\AskForFilename;
use Maatwebsite\LaravelNovaExcel\Requests\ExportActionRequest;
use Maatwebsite\LaravelNovaExcel\Interactions\AskForWriterType;
use Maatwebsite\Excel\Concerns\WithHeadings as WithHeadingsConcern;
use Maatwebsite\LaravelNovaExcel\Requests\ExportActionRequestFactory;

class ExportToExcel extends Action implements FromQuery, WithCustomChunkSize, WithHeadingsConcern, WithMapping
{
    use AskForFilename,
        AskForWriterType,
        Except,
        Only,
        WithChunkCount,
        WithDisk,
        WithFilename,
        WithHeadings,
        WithWriterType;

    /**
     * @var ExportActionRequest|ActionRequest
     */
    protected $request;

    /**
     * @var string
     */
    protected $resource;

    /**
     * @var Builder
     */
    protected $query;

    /**
     * @var Field[]
     */
    protected $actionFields = [];

    /**
     * @var callable|null
     */
    protected $onSuccess;

    /**
     * @var callable|null
     */
    protected $onFailure;

    /**
     * Remove some attributes from this class when serializing,
     * so the action can be queued as exportable.
     * Serialize the request, so we keep information about
     * the resource and lens in the queued jobs.
     *
     * @return array
     */
    public function __sleep()
    {
        if (!$this->request instanceof SerializedRequest) {
            $this->request = SerializedRequest::serialize($this->request);
        }

        return ['headings', 'except', 'only', 'onlyIndexFields', 'request', 'resource'];
    }

    /**
     * Unserialize the action.
     */
    public function __wakeup()
    {
        if ($this->request instanceof SerializedRequest) {
            $this->request = $this->request->unserialize();
        }

        Nova::resourcesIn(app_path('Nova'));
    }

    /**
     * Execute the action for the given request.
     *
     * @param  \Laravel\Nova\Http\Requests\ActionRequest $request
     *
     * @return mixed
     */
    public function handleRequest(ActionRequest $request)
    {
        $this->handleWriterType($request);
        $this->handleFilename($request);

        $this->resource = $request->resource();
        $this->request  = ExportActionRequestFactory::make($request);

        $query = $this->request->toExportQuery();
        $this->handleOnly($this->request);
        $this->handleHeadings($query, $this->request);

        return $this->handle($request, $this->withQuery($query));
    }

    /**
     * @param ActionRequest $request
     * @param Action        $exportable
     *
     * @return array
     */
    public function handle(ActionRequest $request, Action $exportable): array
    {
        $response = Excel::store(
            $exportable,
            $this->getFilename(),
            $this->getDisk(),
            $this->getWriterType()
        );

        if (false === $response) {
            return \is_callable($this->onFailure)
                ? ($this->onFailure)($request, $response)
                : Action::danger(__('Resource could not be exported.'));
        }

        return \is_callable($this->onSuccess)
            ? ($this->onSuccess)($request, $response)
            : Action::message(__('Resource was successfully exported.'));
    }

    /**
     * @param callable $callback
     *
     * @return $this
     */
    public function onSuccess(callable $callback)
    {
        $this->onSuccess = $callback;

        return $this;
    }

    /**
     * @param callable $callback
     *
     * @return $this
     */
    public function onFailure(callable $callback)
    {
        $this->onFailure = $callback;

        return $this;
    }

    /**
     * @param string $name
     *
     * @return $this
     */
    public function withName(string $name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return Builder
     */
    public function query()
    {
        return $this->query;
    }

    /**
     * @return Field[]
     */
    public function fields()
    {
        return $this->actionFields;
    }

    /**
     * @param Model|mixed $row
     *
     * @return array
     */
    public function map($row): array
    {
        $only   = $this->getOnly();
        $except = $this->getExcept();

        if ($row instanceof Model) {
            // If user didn't specify a custom except array, use the hidden columns.
            // User can override this by passing an empty array ->except([])
            // When user specifies with only(), ignore if the column is hidden or not.
            if (!$this->onlyIndexFields && $except === null && (!is_array($only) || count($only) === 0)) {
                $except = $row->getHidden();
            }

            // Make all attributes visible
            $row->setHidden([]);
            $row = $this->replaceFieldValuesWhenOnResource(
                $row,
                is_array($only) ? $only : array_keys($row->attributesToArray())
            );
        }

        if (is_array($only) && count($only) > 0) {
            $row = array_only($row, $only);
        }

        if (is_array($except) && count($except) > 0) {
            $row = array_except($row, $except);
        }

        return $row;
    }

    /**
     * @param Builder $query
     *
     * @return $this
     */
    protected function withQuery($query)
    {
        $this->query = $query;

        return $this;
    }

    /**
     * @return string
     */
    protected function getDefaultExtension(): string
    {
        return $this->getWriterType() ? strtolower($this->getWriterType()) : 'xlsx';
    }

    /**
     * @param Model $model
     * @param array $only
     *
     * @return array
     */
    protected function replaceFieldValuesWhenOnResource(Model $model, array $only = []): array
    {
        $resource = $this->resolveResource($model);
        $fields   = $this->resourceFields($resource);

        $row = [];
        foreach ($fields as $field) {
            if (!$this->isExportableField($field)) {
                continue;
            }

            if (\in_array($field->attribute, $only, true)) {
                $row[$field->attribute] = $field->value;
            } elseif (\in_array($field->name, $only, true)) {
                // When no field could be found by their attribute name, it's most likely a computed field.
                $row[$field->name] = $field->value;
            }
        }

        // Add fields that were requested by ->only(), but are not registered as fields in the Nova resource.
        foreach (array_diff($only, array_keys($row)) as $attribute) {
            if ($model->{$attribute}) {
                $row[$attribute] = $model->{$attribute};
            } else {
                $row[$attribute] = '';
            }
        }

        return $row;
    }

    /**
     * @param \Laravel\Nova\Resource $resource
     *
     * @return Collection
     */
    protected function resourceFields(Resource $resource)
    {
        return $this->request->resourceFields($resource);
    }

    /**
     * @param Model $model
     *
     * @return \Laravel\Nova\Resource
     */
    protected function resolveResource(Model $model): Resource
    {
        $resource = $this->resource;

        return new $resource($model);
    }

    /**
     * @param Field $field
     *
     * @return bool
     */
    protected function isExportableField(Field $field): bool
    {
        return !$field instanceof Gravatar;
    }
}
