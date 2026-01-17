<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Issue;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

class IssueCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Issue::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Issue')
            ->setEntityLabelInPlural('Issues')
            ->setSearchFields(['title', 'culprit', 'fingerprint'])
            ->setDefaultSort(['lastSeenAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('publicId')->hideOnForm()->hideOnIndex();
        yield TextField::new('title');
        yield ChoiceField::new('type')
            ->setChoices([
                'Crash' => Issue::TYPE_CRASH,
                'Error' => Issue::TYPE_ERROR,
                'Log' => Issue::TYPE_LOG,
            ]);
        yield ChoiceField::new('status')
            ->setChoices([
                'Open' => Issue::STATUS_OPEN,
                'Resolved' => Issue::STATUS_RESOLVED,
                'Ignored' => Issue::STATUS_IGNORED,
            ]);
        yield TextField::new('severity')->hideOnIndex();
        yield AssociationField::new('project');
        yield IntegerField::new('occurrenceCount')->setLabel('Occurrences');
        yield IntegerField::new('affectedUsers')->setLabel('Users')->hideOnIndex();
        yield TextareaField::new('culprit')->hideOnIndex();
        yield DateTimeField::new('firstSeenAt')->hideOnForm()->hideOnIndex();
        yield DateTimeField::new('lastSeenAt')->hideOnForm();
        yield DateTimeField::new('resolvedAt')->hideOnForm()->hideOnIndex();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('type')->setChoices([
                'Crash' => Issue::TYPE_CRASH,
                'Error' => Issue::TYPE_ERROR,
                'Log' => Issue::TYPE_LOG,
            ]))
            ->add(ChoiceFilter::new('status')->setChoices([
                'Open' => Issue::STATUS_OPEN,
                'Resolved' => Issue::STATUS_RESOLVED,
                'Ignored' => Issue::STATUS_IGNORED,
            ]))
            ->add(TextFilter::new('severity'))
            ->add(EntityFilter::new('project'));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }
}
