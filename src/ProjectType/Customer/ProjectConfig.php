<?php

namespace Cheppers\Robo\Drupal\ProjectType\Customer;

use Cheppers\Robo\Drupal\ProjectType\Base;

class ProjectConfig extends Base\ProjectConfig
{
    /**
     * {@inheritdoc}
     */
    public $defaultSiteId = 'default';

    /**
     * {@inheritdoc}
     */
    public $siteVariantDirPattern = '{siteBranch}';

    /**
     * {@inheritdoc}
     */
    public $siteVariantUrlPattern = '{siteBranch}.{baseHost}';

    /**
     * @var string
     */
    public $releaseDir = 'release';

    /**
     * @var \Cheppers\Robo\Drupal\Config\SassRootConfig[]
     */
    public $sassRoots = [];

    /**
     * {@inheritdoc}
     */
    protected function initPropertyMapping()
    {
        $this->propertyMapping += [
            'releaseDir' => 'releaseDir',
            'sassRoots' => 'sassRoots',
        ];
        parent::initPropertyMapping();

        return $this;
    }

    public function populateDefaultValues()
    {
        parent::populateDefaultValues();

        foreach ($this->sassRoots as $id => $sassRoot) {
            $sassRoot->id = $id;
        }

        return $this;
    }
}
