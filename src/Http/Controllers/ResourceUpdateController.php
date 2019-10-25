<?php

namespace Opanegro\NovaCustomController\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Actions\ActionEvent;
use Laravel\Nova\Http\Requests\UpdateResourceRequest;
use ReflectionException;

class ResourceUpdateController extends Controller
{
    /**
     * Create a new resource.
     *
     * @param UpdateResourceRequest $request
     * @return JsonResponse
     * @throws AuthorizationException
     * @throws ReflectionException
     */
    public function handle(UpdateResourceRequest $request)
    {
        $request->findResourceOrFail()->authorizeToUpdate($request);

        $resource = $request->resource();

        if (isset($resource::$setCustomRequests) && count($resource::$setCustomRequests) > 0) {
            $request->request->add($resource::$setCustomRequests);
        }

        if (check_override_method($resource, 'customUpdateController')) {
            $model = $request->findModelQuery()->lockForUpdate()->firstOrFail();
            return $resource::customUpdateController($request, $model);
        } else {
            $resource::validateForUpdate($request);

            $model = DB::transaction(function () use ($request, $resource) {
                $model = $request->findModelQuery()->lockForUpdate()->firstOrFail();

                if ($this->modelHasBeenUpdatedSinceRetrieval($request, $model)) {
                    return response('', 409)->throwResponse();
                }

                [$model, $callbacks] = $resource::fillForUpdate($request, $model);

                if (check_override_method($resource, 'beforeUpdated')) {
                    $resource::beforeUpdated($request, $model);
                }

                if (isset($resource::$unsetCustomFields) && count($resource::$unsetCustomFields) > 0) {
                    foreach ($resource::$unsetCustomFields as $field) unset($model->$field);
                }

                ActionEvent::forResourceUpdate($request->user(), $model)->save();

                $model->save();

                if (check_override_method($resource, 'afterUpdated')) {
                    $resource::afterUpdated($request, $model);
                }

                if (check_override_method($resource, 'afterSave')) {
                    $resource::afterSave($request, $model);
                }

                collect($callbacks)->each->__invoke();

                return $model;
            });

            return response()->json([
                'id' => $model->getKey(),
                'resource' => $model->attributesToArray(),
                'redirect' => $resource::redirectAfterUpdate($request, $request->newResourceWith($model)),
            ]);
        }
    }

    /**
     * Determine if the model has been updated since it was retrieved.
     *
     * @param UpdateResourceRequest $request
     * @param  Model  $model
     * @return bool
     */
    protected function modelHasBeenUpdatedSinceRetrieval(UpdateResourceRequest $request, $model)
    {
        $column = $model->getUpdatedAtColumn();

        if (! $model->{$column}) {
            return false;
        }

        return $request->input('_retrieved_at') && $model->usesTimestamps() && $model->{$column}->gt(
            Carbon::createFromTimestamp($request->input('_retrieved_at'))
        );
    }
}
