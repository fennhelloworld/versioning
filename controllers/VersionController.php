<?php

namespace amilna\versioning\controllers;

use Yii;
use amilna\versioning\models\Record;
use amilna\versioning\models\Version;
use amilna\versioning\models\VersionSearch;
use amilna\versioning\models\GrpUsr;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;

/**
 * VersionController implements the CRUD actions for Version model.
 */
class VersionController extends Controller
{
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'delete' => ['post'],
                    'apply' => ['post'],
                ],
            ],
        ];
    }
	
	public function actionApply($id)
	{
		$model = $this->findModel($id);
		
		if ($model) {			
			if (!$model->status)
			{			
				$route_id = $model->route_id;
				
				$versions = VersionSearch::find()			
						->andWhere("route_id = :route_id OR concat(',',substring(route_ids from 2 for (length(route_ids)-2)),',') LIKE :lk",[":route_id"=>$route_id,":lk"=>'%,'.$route_id.',%'])
						->andWhere("type = :type",[":type"=>$model->type])
						->all();								
										
				$res = true;		
				$transaction = Yii::$app->db->beginTransaction();
				try {
					foreach ($versions as $mv)
					{
						$record_id = $mv->record->record_id;
						$modelClass = $mv->record->model;
						
						if ($record_id != null)
						{														
							$sql = "UPDATE ".Version::tableName()." set status = false where record_id = :reid and status = true";
							Yii::$app->db->createCommand($sql)->bindValues([":reid"=>$mv->record_id])->execute();
						}	
						else
						{
							$done = Version::findOne(["record_id"=>$mv->record_id,"route_id"=>$route_id]);
							if (!$done)
							{
								$recs = Version::findAll(["record_id"=>$mv->record_id]);							
								foreach ($recs as $r)
								{
									$sql = "";
									$key = [];
									foreach (json_decode($r->record_attributes) as $a=>$v)
									{
										$sql .= ($sql == ""?"":" AND ").$a.($v === null?" is null":" = :".$a);
										if ($v !== null)
										{
											$key[":".$a] = $v;	
										}
									}									
									$rmod = $modelClass::find()->where($sql,$key)->one();								
									if ($rmod && !in_array($route_id,json_decode($r->route_ids)))
									{
										$modelClass::deleteAll($sql,$key);
									}
									$r->route_id = $route_id;								
									$r->save();
								}																																	
							}
						}				
						
						$version = $mv->version;
						$mv->status = true;												
						
						if (($record_id == null && $version->isNewRecord) || $record_id != null)
						{														
							foreach ($version->behaviors as $k=>$b)
							{								
								if ($k === "tree")
								{																											
									$lid =  $b->leftAttribute;
									$rid =  $b->rightAttribute;
									$did =  $b->depthAttribute;
									
									$parent = $version->find()->andWhere($lid." < ".$version->$lid." AND ".$rid." > ".$version->$rid." AND (".$did."+1) = ".$version->$did)->one();												
									
									if ($parent)	
									{
										$version->prependTo($parent);
									}
									else
									{
										if (!empty($b->treeAttribute))
										{
											$version->makeRoot();
										}
										else
										{
											$parent = $version->find()->andWhere($did." = 1 ")->one();
											if ($parent)
											{
												$version->prependTo($parent);
											}
											else
											{
												$version->makeRoot();
											}
										}
									}							
								}								
							}														
							
							$res = (!$version->save()?false:$res);
							if ($record_id != null)
							{
								$pk = $version->getPrimaryKey(true);
								if (is_array($pk))
								{
									foreach ($pk as $pk_key=>$pk_val)
									{										
										$mv->record->record_id = $version->$pk_key;								
										$res = (!$mv->record->save()?false:$res);
									}
								}
								else
								{
									$res = false;	
								}
							}
						}					
						
						$res = (!$mv->save()?false:$res);
					}
										
					if ($res)
					{						
						$transaction->commit();
					}
					else
					{						
						$transaction->rollBack();
					}
				} catch (Exception $e) {					
					$transaction->rollBack();				
				}
			}																						
		}
		
		return $this->redirect(['index']);
	}

	public function actionReadall($models = false)
    {                
        $user_id = Yii::$app->user->id;
        
        if ($user_id > 0)
        {
        
			$res = Yii::$app->db->createCommand("UPDATE 
					".Record::tableName()."
					SET viewers = concat(viewers,',',".$user_id.")
					WHERE concat(',',".Record::tableName().".viewers,',') not like '%,".$user_id.",%'".($models?" AND model = ANY (array['".str_replace(",","','",$models)."'])":""))
					->execute();											
        
		}        
				
		return $this->redirect($_SERVER["HTTP_REFERER"]);
    }
	
    /**
     * Lists all Version models.
     * @params string $format, array $arraymap, string $term
     * @return mixed
     */
    public function actionIndex($format= false,$arraymap= false,$term = false)
    {
        $searchModel = new VersionSearch();        
        $req = Yii::$app->request->queryParams;                                        
        if ($term) { $req[basename(str_replace("\\","/",get_class($searchModel)))]["term"] = $term;}        
        $dataProvider = $searchModel->search($req);				                
        $query = $dataProvider->query;
        
        $module = Yii::$app->getModule("versioning");
        $allow = false;
        if (isset(Yii::$app->user->identity->isAdmin))
		{
			$allow = Yii::$app->user->identity->isAdmin;
		}
		else
		{
			$allow = in_array(Yii::$app->user->identity->username,$module->admins);
		}																		
        
        if (!$allow)
        {
			$query->joinWith(['record','group']);
			$query->andWhere(GrpUsr::tableName().'.user_id = :uid OR '.Record::tableName().'.owner_id = :uid',[':uid'=>Yii::$app->user->id]);
		}
                
        $dataProvider->pagination = [
			"pageSize"=>10	
		];
		
		if (!isset($req["sort"]))
		{
			$dataProvider->query->orderBy('time DESC');
		}
		        
        if ($format == 'json')
        {
			$model = [];
			foreach ($dataProvider->getModels() as $d)
			{
				$obj = $d->attributes;
				if ($arraymap)
				{
					$map = explode(",",$arraymap);
					if (count($map) == 1)
					{
						$obj = (isset($d[$arraymap])?$d[$arraymap]:null);
					}
					else
					{
						$obj = [];					
						foreach ($map as $a)
						{
							$k = explode(":",$a);						
							$v = (count($k) > 1?$k[1]:$k[0]);
							$obj[$k[0]] = ($v == "Obj"?json_encode($d->attributes):(isset($d->$v)?$d->$v:null));
						}
					}
				}
				
				if ($term)
				{
					if (!in_array($obj,$model))
					{
						array_push($model,$obj);
					}
				}
				else
				{	
					array_push($model,$obj);
				}
			}			
			return \yii\helpers\Json::encode($model);	
		}
		else
		{
			return $this->render('index', [
				'searchModel' => $searchModel,
				'dataProvider' => $dataProvider,
			]);
		}	
    }

    /**
     * Displays a single Version model.
     * @param integer $id
     * @additionalParam string $format
     * @return mixed
     */
    public function actionView($id,$format= false)
    {
        $model = $this->findModel($id);
        
        if ($format == 'json')
        {
			return \yii\helpers\Json::encode($model);	
		}
		else
		{
			return $this->render('view', [
				'model' => $model,
			]);
		}        
    }

    /**
     * Creates a new Version model.
     * If creation is successful, the browser will be redirected to the 'view' page.
     * @return mixed
     */
    public function actionCreate()
    {
        $model = new Version();
		$model->isdel = 0;
		
        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            return $this->redirect(['view', 'id' => $model->id]);
        } else {
            return $this->render('create', [
                'model' => $model,
            ]);
        }
    }

    /**
     * Updates an existing Version model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     */
    public function actionUpdate($id)
    {
        $model = $this->findModel($id);

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            return $this->redirect(['view', 'id' => $model->id]);
        } else {
            return $this->render('update', [
                'model' => $model,
            ]);
        }
    }

    /**
     * Deletes an existing Version model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     */
    public function actionDelete($id)
    {        
		$model = $this->findModel($id);        
        $model->isdel = 1;
        if ($model->status)
        {
			$model->status = false;
			$parent = $model->parents(1)->one();
			if (!$parent)
			{
				$parent = $model->children(1)->one();
			}
			$parent->status = true;
			$version = $parent->version;
			$parent->save();
			$version->save();
		}        
        $model->save();
        //$model->delete(); //this will true delete
        
        return $this->redirect(['index']);
    }

    /**
     * Finds the Version model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return Version the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id)
    {
        if (($model = Version::findOne($id)) !== null) {
            return $model;
        } else {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
    }
}
