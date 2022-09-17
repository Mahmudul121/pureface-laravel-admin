<?php


namespace Marvel\Database\Repositories;


use Marvel\Database\Models\Delivery;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;



class DeliveryRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'ref_no'        => 'like',
    ];

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
        }
    }


    /**
     * Configure the Model
     **/
    public function model()
    {
        return Delivery::class;
    }
}
