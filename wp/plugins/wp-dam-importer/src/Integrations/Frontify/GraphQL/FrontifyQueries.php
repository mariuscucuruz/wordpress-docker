<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Frontify\GraphQL;

final class FrontifyQueries
{
    public const string CURRENT_USER = <<<'GQL'
    query {
      currentUser {
        email
        name
      }
    }
    GQL;

    public const string BRANDS = <<<'GQL'
    query Brands {
      brands {
        id
        name
      }
    }
    GQL;

    public const string BRAND_LIBRARIES = <<<'GQL'
    query BrandLibraries($id: ID!, $limit: Int!, $page: Int!) {
      brand(id: $id) {
        id
        name
        libraries(limit: $limit, page: $page) {
          total
          items {
            id
            name
            __typename
          }
        }
      }
    }
    GQL;

    public const string LIBRARY_ASSETS = <<<'GQL'
        query LibraryAssets($id: ID!, $limit: Int!, $page: Int!) {
          library(id: $id) {
            id
            name
            assets(limit: $limit, page: $page) {
              total
              items {
                __typename
                ... on Image {
                  id
                  title
                  description
                  size
                  filename
                  extension
                  createdAt
                  expiresAt
                  modifiedAt
                  thumbnailUrl
                }
                ... on Video {
                  id
                  title
                  description
                  size
                  filename
                  extension
                  width
                  height
                  duration
                  bitrate
                  createdAt
                  fileCreatedAt
                  expiresAt
                  modifiedAt
                  thumbnailUrl
                }
              }
            }
          }
        }
    GQL;

    public const string ASSET_DOWNLOAD_URL = <<<'GQL'
        query AssetById($id: ID!) {
          asset(id: $id) {
            __typename
            ... on Image {
              id
              previewUrl
              downloadUrl
            }
            ... on Video {
              id
              previewUrl
              downloadUrl
            }
          }
        }
        GQL;
}
