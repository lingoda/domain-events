# 2.0

## Breaking Change

Replaces natively serialised object column with JSON field via [dunglas/doctrine-json-odm](https://github.com/dunglas/doctrine-json-odm).

The column name was changed (from domainevent to event) to help migrations. 
A possible migration logic could use doctrine MigrationFactory and inject some services, then:

- Add new column
- Go through all records
- unserialise using php
- serialise using `dunglas_doctrine_json_odm.serializer` service
- populate new column
