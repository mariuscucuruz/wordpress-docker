<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\EventAnalytics\Enums;

enum EventCategories: string
{
    case Teams = 'Teams';
    case Debug = 'Debug';
    case Album = 'Album';
    case Albums = 'Albums';
    case Folder = 'Folder';
    case Sidebar = 'Sidebar';
    case Library = 'Library';
    case Profile = 'Profile';
    case Folders = 'Folders';
    case Services = 'Services';
    case Settings = 'Settings';
    case PageView = 'PageView';
    case Dashboard = 'Dashboard';
    case Catalogue = 'Catalogue';
    case SyncModal = 'Sync Modal';
    case Duplicates = 'Duplicates';
    case Connections = 'Connections';
    case Collections = 'Collections';
    case SearchModal = 'Search Modal';
    case SearchResults = 'Search Results';
    case Administration = 'Administration';
    case AdvancedSearch = 'Advanced Search';
    case AssetDashboard = 'Asset Dashboard';

    case Unknown = 'Unknown';

    public static function all()
    {
        return array_column(self::cases(), 'value');
    }
}
