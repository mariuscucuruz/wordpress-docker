<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient\Models;

class Account extends BaseModel
{
    protected $account_id;

    protected $name;

    protected $email;

    protected $profile_image;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->account_id = $this->getDataProperty('account_id');
        $this->name = $this->getDataProperty('name');
        $this->email = $this->getDataProperty('email');
        $this->profile_image = $this->getDataProperty('profile_image');
    }

    public function getAccountId()
    {
        return $this->account_id;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function getProfilePhotoUrl()
    {
        return $this->profile_image;
    }
}
