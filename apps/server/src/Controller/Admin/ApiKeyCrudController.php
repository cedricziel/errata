<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ApiKey;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

class ApiKeyCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ApiKey::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('API Key')
            ->setEntityLabelInPlural('API Keys')
            ->setSearchFields(['keyPrefix', 'label', 'environment'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('keyPrefix')->setLabel('Key');
        yield TextField::new('label');
        yield AssociationField::new('project');
        yield TextField::new('environment');
        yield ArrayField::new('scopes')->hideOnIndex();
        yield BooleanField::new('isActive');
        yield DateTimeField::new('expiresAt')->hideOnIndex();
        yield DateTimeField::new('lastUsedAt');
        yield DateTimeField::new('createdAt')->hideOnForm();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(BooleanFilter::new('isActive'))
            ->add(TextFilter::new('environment'))
            ->add(EntityFilter::new('project'));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }
}
