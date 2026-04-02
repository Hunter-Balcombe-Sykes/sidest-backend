[
  {
    "full_schema_report": {
      "schemas": [
        {
          "schema_name": "analytics",
          "tables": [
            {
              "table_name": "booking_events",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "site_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 4,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "occurred_at",
                  "ordinal_position": 5,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "status",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'completed'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "source",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'site_booking_checkout'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "square_booking_id",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "square_payment_id",
                  "ordinal_position": 9,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "service_variation_id",
                  "ordinal_position": 10,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "service_name",
                  "ordinal_position": 11,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "payment_method",
                  "ordinal_position": 12,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "customer_name",
                  "ordinal_position": 13,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "customer_email",
                  "ordinal_position": 14,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "customer_phone",
                  "ordinal_position": 15,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 16,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'AUD'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "amount_paid_cents",
                  "ordinal_position": 17,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "raw_payload",
                  "ordinal_position": 18,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 19,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 20,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "appointment_start_at",
                  "ordinal_position": 21,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "booking_events_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                },
                {
                  "fk_name": "booking_events_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "booking_events_site_id_fkey",
                  "column_name": "site_id",
                  "foreign_schema": "core",
                  "foreign_table": "sites",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "booking_events_brand_occurred_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "occurred_at"
                  ],
                  "index_definition": "CREATE INDEX booking_events_brand_occurred_idx ON analytics.booking_events USING btree (brand_professional_id, occurred_at DESC)"
                },
                {
                  "index_name": "booking_events_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX booking_events_pkey ON analytics.booking_events USING btree (id)"
                },
                {
                  "index_name": "booking_events_professional_appointment_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "appointment_start_at"
                  ],
                  "index_definition": "CREATE INDEX booking_events_professional_appointment_idx ON analytics.booking_events USING btree (professional_id, appointment_start_at DESC)"
                },
                {
                  "index_name": "booking_events_professional_booking_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "square_booking_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX booking_events_professional_booking_uq ON analytics.booking_events USING btree (professional_id, square_booking_id) WHERE (square_booking_id IS NOT NULL)"
                },
                {
                  "index_name": "booking_events_professional_occurred_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "occurred_at"
                  ],
                  "index_definition": "CREATE INDEX booking_events_professional_occurred_idx ON analytics.booking_events USING btree (professional_id, occurred_at DESC)"
                },
                {
                  "index_name": "booking_events_site_occurred_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "site_id",
                    "occurred_at"
                  ],
                  "index_definition": "CREATE INDEX booking_events_site_occurred_idx ON analytics.booking_events USING btree (site_id, occurred_at DESC)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "booking_metrics_daily",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "day",
                  "ordinal_position": 1,
                  "data_type": "date",
                  "udt_name": "date",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 3,
                  "data_type": "character",
                  "udt_name": "bpchar",
                  "character_maximum_length": 3,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "timezone",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "bookings_count",
                  "ordinal_position": 5,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "total_spent_cents",
                  "ordinal_position": 6,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "paid_bookings_count",
                  "ordinal_position": 7,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "customers_count",
                  "ordinal_position": 8,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 9,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "currency_code",
                "day",
                "professional_id",
                "timezone"
              ],
              "foreign_keys": [
                {
                  "fk_name": "booking_metrics_daily_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "booking_metrics_daily_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "day",
                    "professional_id",
                    "currency_code",
                    "timezone"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX booking_metrics_daily_pkey ON analytics.booking_metrics_daily USING btree (day, professional_id, currency_code, timezone)"
                },
                {
                  "index_name": "booking_metrics_daily_professional_day_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "day",
                    "professional_id"
                  ],
                  "index_definition": "CREATE INDEX booking_metrics_daily_professional_day_idx ON analytics.booking_metrics_daily USING btree (professional_id, day DESC)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "booking_metrics_hourly",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "hour_start",
                  "ordinal_position": 1,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 3,
                  "data_type": "character",
                  "udt_name": "bpchar",
                  "character_maximum_length": 3,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "timezone",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "bookings_count",
                  "ordinal_position": 5,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "total_spent_cents",
                  "ordinal_position": 6,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "paid_bookings_count",
                  "ordinal_position": 7,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "customers_count",
                  "ordinal_position": 8,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 9,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "currency_code",
                "hour_start",
                "professional_id",
                "timezone"
              ],
              "foreign_keys": [
                {
                  "fk_name": "booking_metrics_hourly_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "booking_metrics_hourly_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "hour_start",
                    "professional_id",
                    "currency_code",
                    "timezone"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX booking_metrics_hourly_pkey ON analytics.booking_metrics_hourly USING btree (hour_start, professional_id, currency_code, timezone)"
                },
                {
                  "index_name": "booking_metrics_hourly_professional_hour_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "hour_start",
                    "professional_id"
                  ],
                  "index_definition": "CREATE INDEX booking_metrics_hourly_professional_hour_idx ON analytics.booking_metrics_hourly USING btree (professional_id, hour_start DESC)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "brand_commission_daily",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "day",
                  "ordinal_position": 1,
                  "data_type": "date",
                  "udt_name": "date",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "affiliate_professional_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "payout_status",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 5,
                  "data_type": "character",
                  "udt_name": "bpchar",
                  "character_maximum_length": 3,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "timezone",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "accrual_cents",
                  "ordinal_position": 7,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "reversal_cents",
                  "ordinal_position": 8,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "payout_cents",
                  "ordinal_position": 9,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "net_outstanding_cents",
                  "ordinal_position": 10,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "entries_count",
                  "ordinal_position": 11,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 12,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "affiliate_professional_id",
                "brand_professional_id",
                "currency_code",
                "day",
                "payout_status",
                "timezone"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_commission_daily_affiliate_professional_id_fkey",
                  "column_name": "affiliate_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "brand_commission_daily_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "brand_commission_daily_affiliate_day_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "day",
                    "affiliate_professional_id"
                  ],
                  "index_definition": "CREATE INDEX brand_commission_daily_affiliate_day_idx ON analytics.brand_commission_daily USING btree (affiliate_professional_id, day DESC)"
                },
                {
                  "index_name": "brand_commission_daily_brand_day_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "day",
                    "brand_professional_id"
                  ],
                  "index_definition": "CREATE INDEX brand_commission_daily_brand_day_idx ON analytics.brand_commission_daily USING btree (brand_professional_id, day DESC)"
                },
                {
                  "index_name": "brand_commission_daily_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "day",
                    "brand_professional_id",
                    "affiliate_professional_id",
                    "payout_status",
                    "currency_code",
                    "timezone"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_commission_daily_pkey ON analytics.brand_commission_daily USING btree (day, brand_professional_id, affiliate_professional_id, payout_status, currency_code, timezone)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "brand_influencer_daily",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "day",
                  "ordinal_position": 1,
                  "data_type": "date",
                  "udt_name": "date",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "affiliate_professional_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 4,
                  "data_type": "character",
                  "udt_name": "bpchar",
                  "character_maximum_length": 3,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "timezone",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "orders_count",
                  "ordinal_position": 6,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "gross_cents",
                  "ordinal_position": 7,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "refunded_cents",
                  "ordinal_position": 8,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "returned_cents",
                  "ordinal_position": 9,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "net_cents",
                  "ordinal_position": 10,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "commission_accrued_cents",
                  "ordinal_position": 11,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "commission_reversed_cents",
                  "ordinal_position": 12,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "commission_net_cents",
                  "ordinal_position": 13,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 14,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "customers_count",
                  "ordinal_position": 15,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "affiliate_professional_id",
                "brand_professional_id",
                "currency_code",
                "day",
                "timezone"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_influencer_daily_affiliate_professional_id_fkey",
                  "column_name": "affiliate_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "brand_influencer_daily_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "brand_influencer_daily_brand_affiliate_day_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "day",
                    "brand_professional_id",
                    "affiliate_professional_id"
                  ],
                  "index_definition": "CREATE INDEX brand_influencer_daily_brand_affiliate_day_idx ON analytics.brand_influencer_daily USING btree (brand_professional_id, affiliate_professional_id, day DESC)"
                },
                {
                  "index_name": "brand_influencer_daily_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "day",
                    "brand_professional_id",
                    "affiliate_professional_id",
                    "currency_code",
                    "timezone"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_influencer_daily_pkey ON analytics.brand_influencer_daily USING btree (day, brand_professional_id, affiliate_professional_id, currency_code, timezone)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "brand_influencer_product_daily",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "day",
                  "ordinal_position": 1,
                  "data_type": "date",
                  "udt_name": "date",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "affiliate_professional_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_product_id",
                  "ordinal_position": 4,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "category",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "collection",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 7,
                  "data_type": "character",
                  "udt_name": "bpchar",
                  "character_maximum_length": 3,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "timezone",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "units_sold",
                  "ordinal_position": 9,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "orders_count",
                  "ordinal_position": 10,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "gross_cents",
                  "ordinal_position": 11,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "refunded_cents",
                  "ordinal_position": 12,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "returned_cents",
                  "ordinal_position": 13,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "net_cents",
                  "ordinal_position": 14,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "commission_net_cents",
                  "ordinal_position": 15,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 16,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "affiliate_professional_id",
                "brand_product_id",
                "brand_professional_id",
                "currency_code",
                "day",
                "timezone"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_influencer_product_daily_affiliate_professional_id_fkey",
                  "column_name": "affiliate_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "brand_influencer_product_daily_brand_product_id_fkey",
                  "column_name": "brand_product_id",
                  "foreign_schema": "retail",
                  "foreign_table": "brand_products",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "brand_influencer_product_daily_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "brand_influencer_product_daily_affiliate_day_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "day",
                    "affiliate_professional_id"
                  ],
                  "index_definition": "CREATE INDEX brand_influencer_product_daily_affiliate_day_idx ON analytics.brand_influencer_product_daily USING btree (affiliate_professional_id, day DESC)"
                },
                {
                  "index_name": "brand_influencer_product_daily_brand_day_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "day",
                    "brand_professional_id"
                  ],
                  "index_definition": "CREATE INDEX brand_influencer_product_daily_brand_day_idx ON analytics.brand_influencer_product_daily USING btree (brand_professional_id, day DESC)"
                },
                {
                  "index_name": "brand_influencer_product_daily_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "day",
                    "brand_professional_id",
                    "affiliate_professional_id",
                    "brand_product_id",
                    "currency_code",
                    "timezone"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_influencer_product_daily_pkey ON analytics.brand_influencer_product_daily USING btree (day, brand_professional_id, affiliate_professional_id, brand_product_id, currency_code, timezone)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "brand_metrics_daily",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "day",
                  "ordinal_position": 1,
                  "data_type": "date",
                  "udt_name": "date",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 3,
                  "data_type": "character",
                  "udt_name": "bpchar",
                  "character_maximum_length": 3,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "timezone",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "orders_count",
                  "ordinal_position": 5,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "gross_cents",
                  "ordinal_position": 6,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "refunded_cents",
                  "ordinal_position": 7,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "returned_cents",
                  "ordinal_position": 8,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "net_cents",
                  "ordinal_position": 9,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 10,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "brand_professional_id",
                "currency_code",
                "day",
                "timezone"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_metrics_daily_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "brand_metrics_daily_brand_day_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "day",
                    "brand_professional_id"
                  ],
                  "index_definition": "CREATE INDEX brand_metrics_daily_brand_day_idx ON analytics.brand_metrics_daily USING btree (brand_professional_id, day DESC)"
                },
                {
                  "index_name": "brand_metrics_daily_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "day",
                    "brand_professional_id",
                    "currency_code",
                    "timezone"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_metrics_daily_pkey ON analytics.brand_metrics_daily USING btree (day, brand_professional_id, currency_code, timezone)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "brand_metrics_hourly",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "hour_start",
                  "ordinal_position": 1,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 3,
                  "data_type": "character",
                  "udt_name": "bpchar",
                  "character_maximum_length": 3,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "timezone",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "orders_count",
                  "ordinal_position": 5,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "gross_cents",
                  "ordinal_position": 6,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "refunded_cents",
                  "ordinal_position": 7,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "returned_cents",
                  "ordinal_position": 8,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "net_cents",
                  "ordinal_position": 9,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "commission_net_cents",
                  "ordinal_position": 10,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 11,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "brand_professional_id",
                "currency_code",
                "hour_start",
                "timezone"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_metrics_hourly_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "brand_metrics_hourly_brand_hour_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "hour_start",
                    "brand_professional_id"
                  ],
                  "index_definition": "CREATE INDEX brand_metrics_hourly_brand_hour_idx ON analytics.brand_metrics_hourly USING btree (brand_professional_id, hour_start DESC)"
                },
                {
                  "index_name": "brand_metrics_hourly_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "hour_start",
                    "brand_professional_id",
                    "currency_code",
                    "timezone"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_metrics_hourly_pkey ON analytics.brand_metrics_hourly USING btree (hour_start, brand_professional_id, currency_code, timezone)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "brand_product_daily",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "day",
                  "ordinal_position": 1,
                  "data_type": "date",
                  "udt_name": "date",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_product_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "category",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "collection",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 6,
                  "data_type": "character",
                  "udt_name": "bpchar",
                  "character_maximum_length": 3,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "timezone",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "units_sold",
                  "ordinal_position": 8,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "orders_count",
                  "ordinal_position": 9,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "gross_cents",
                  "ordinal_position": 10,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "refunded_cents",
                  "ordinal_position": 11,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "returned_cents",
                  "ordinal_position": 12,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "net_cents",
                  "ordinal_position": 13,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "commission_net_cents",
                  "ordinal_position": 14,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 15,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "brand_product_id",
                "brand_professional_id",
                "currency_code",
                "day",
                "timezone"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_product_daily_brand_product_id_fkey",
                  "column_name": "brand_product_id",
                  "foreign_schema": "retail",
                  "foreign_table": "brand_products",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "brand_product_daily_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "brand_product_daily_brand_day_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "day",
                    "brand_professional_id"
                  ],
                  "index_definition": "CREATE INDEX brand_product_daily_brand_day_idx ON analytics.brand_product_daily USING btree (brand_professional_id, day DESC)"
                },
                {
                  "index_name": "brand_product_daily_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "day",
                    "brand_professional_id",
                    "brand_product_id",
                    "currency_code",
                    "timezone"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_product_daily_pkey ON analytics.brand_product_daily USING btree (day, brand_professional_id, brand_product_id, currency_code, timezone)"
                },
                {
                  "index_name": "brand_product_daily_product_day_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "day",
                    "brand_product_id"
                  ],
                  "index_definition": "CREATE INDEX brand_product_daily_product_day_idx ON analytics.brand_product_daily USING btree (brand_product_id, day DESC)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "lead_submissions",
              "table_type": "BASE TABLE",
              "table_comment": "Customer lead submissions with form timing and outcomes",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "occurred_at",
                  "ordinal_position": 2,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "subdomain",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "site_id",
                  "ordinal_position": 4,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 5,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "customer_id",
                  "ordinal_position": 6,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "ip_hash",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "user_agent",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "referrer",
                  "ordinal_position": 9,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "outcome",
                  "ordinal_position": 10,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Submission outcome: created, rate_limited, site_not_found, etc."
                },
                {
                  "column_name": "form_started_at_ms",
                  "ordinal_position": 11,
                  "data_type": "bigint",
                  "udt_name": "int8",
                  "character_maximum_length": null,
                  "numeric_precision": 64,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "lead_submissions_customer_id_fkey",
                  "column_name": "customer_id",
                  "foreign_schema": "core",
                  "foreign_table": "customers",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                },
                {
                  "fk_name": "lead_submissions_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                },
                {
                  "fk_name": "lead_submissions_site_id_fkey",
                  "column_name": "site_id",
                  "foreign_schema": "core",
                  "foreign_table": "sites",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "lead_submissions_ip_time_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "occurred_at",
                    "ip_hash"
                  ],
                  "index_definition": "CREATE INDEX lead_submissions_ip_time_idx ON analytics.lead_submissions USING btree (ip_hash, occurred_at DESC)"
                },
                {
                  "index_name": "lead_submissions_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX lead_submissions_pkey ON analytics.lead_submissions USING btree (id)"
                },
                {
                  "index_name": "lead_submissions_prof_time_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "occurred_at",
                    "professional_id"
                  ],
                  "index_definition": "CREATE INDEX lead_submissions_prof_time_idx ON analytics.lead_submissions USING btree (professional_id, occurred_at DESC)"
                },
                {
                  "index_name": "lead_submissions_site_time_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "occurred_at",
                    "site_id"
                  ],
                  "index_definition": "CREATE INDEX lead_submissions_site_time_idx ON analytics.lead_submissions USING btree (site_id, occurred_at DESC)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": [
                {
                  "policy_name": "lead_submissions_public_insert",
                  "command": "INSERT",
                  "is_permissive": true,
                  "using_expression": null,
                  "with_check_expression": "(EXISTS ( SELECT 1\n   FROM core.sites s\n  WHERE ((s.id = lead_submissions.site_id) AND (s.professional_id = lead_submissions.professional_id) AND (s.is_published = true))))"
                },
                {
                  "policy_name": "lead_submissions_staff_all",
                  "command": "ALL",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid())))",
                  "with_check_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid())))"
                }
              ]
            },
            {
              "table_name": "link_clicks",
              "table_type": "BASE TABLE",
              "table_comment": "Click tracking for link blocks",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "site_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "link_block_id",
                  "ordinal_position": 4,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "occurred_at",
                  "ordinal_position": 5,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "session_id",
                  "ordinal_position": 6,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "visitor_id",
                  "ordinal_position": 7,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "ip_hash",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "user_agent",
                  "ordinal_position": 9,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "referrer",
                  "ordinal_position": 10,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "utm_source",
                  "ordinal_position": 11,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "utm_medium",
                  "ordinal_position": 12,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "utm_campaign",
                  "ordinal_position": 13,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 14,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "link_clicks_link_block_id_fkey",
                  "column_name": "link_block_id",
                  "foreign_schema": "core",
                  "foreign_table": "blocks",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "link_clicks_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "link_clicks_site_id_fkey",
                  "column_name": "site_id",
                  "foreign_schema": "core",
                  "foreign_table": "sites",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "analytics_link_clicks_professional_occurred_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "occurred_at"
                  ],
                  "index_definition": "CREATE INDEX analytics_link_clicks_professional_occurred_idx ON analytics.link_clicks USING btree (professional_id, occurred_at)"
                },
                {
                  "index_name": "link_clicks_link_time_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "link_block_id",
                    "occurred_at"
                  ],
                  "index_definition": "CREATE INDEX link_clicks_link_time_idx ON analytics.link_clicks USING btree (link_block_id, occurred_at)"
                },
                {
                  "index_name": "link_clicks_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX link_clicks_pkey ON analytics.link_clicks USING btree (id)"
                },
                {
                  "index_name": "link_clicks_pro_date_range_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "link_block_id",
                    "occurred_at"
                  ],
                  "index_definition": "CREATE INDEX link_clicks_pro_date_range_idx ON analytics.link_clicks USING btree (professional_id, occurred_at DESC) INCLUDE (link_block_id)"
                },
                {
                  "index_name": "link_clicks_professional_time_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "occurred_at"
                  ],
                  "index_definition": "CREATE INDEX link_clicks_professional_time_idx ON analytics.link_clicks USING btree (professional_id, occurred_at)"
                },
                {
                  "index_name": "link_clicks_site_time_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "site_id",
                    "occurred_at"
                  ],
                  "index_definition": "CREATE INDEX link_clicks_site_time_idx ON analytics.link_clicks USING btree (site_id, occurred_at)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": [
                {
                  "policy_name": "link_clicks_anyone_insert_valid_block",
                  "command": "INSERT",
                  "is_permissive": true,
                  "using_expression": null,
                  "with_check_expression": "(EXISTS ( SELECT 1\n   FROM (core.blocks b\n     JOIN core.sites s ON ((s.id = b.site_id)))\n  WHERE ((b.id = link_clicks.link_block_id) AND (b.site_id = link_clicks.site_id) AND (b.professional_id = link_clicks.professional_id) AND (b.is_active = true) AND (s.is_published = true))))"
                },
                {
                  "policy_name": "link_clicks_staff_all",
                  "command": "ALL",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = ( SELECT ( SELECT auth.uid() AS uid) AS uid))))",
                  "with_check_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = ( SELECT ( SELECT auth.uid() AS uid) AS uid))))"
                }
              ]
            },
            {
              "table_name": "professional_customer_daily",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "day",
                  "ordinal_position": 1,
                  "data_type": "date",
                  "udt_name": "date",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "affiliate_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "timezone",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "customers_count",
                  "ordinal_position": 4,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "new_customers_count",
                  "ordinal_position": 5,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "returning_customers_count",
                  "ordinal_position": 6,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 7,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "affiliate_professional_id",
                "day",
                "timezone"
              ],
              "foreign_keys": [
                {
                  "fk_name": "professional_customer_daily_affiliate_professional_id_fkey",
                  "column_name": "affiliate_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "professional_customer_daily_affiliate_day_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "day",
                    "affiliate_professional_id"
                  ],
                  "index_definition": "CREATE INDEX professional_customer_daily_affiliate_day_idx ON analytics.professional_customer_daily USING btree (affiliate_professional_id, day DESC)"
                },
                {
                  "index_name": "professional_customer_daily_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "day",
                    "affiliate_professional_id",
                    "timezone"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX professional_customer_daily_pkey ON analytics.professional_customer_daily USING btree (day, affiliate_professional_id, timezone)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "professional_metrics_daily",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "day",
                  "ordinal_position": 1,
                  "data_type": "date",
                  "udt_name": "date",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "affiliate_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 3,
                  "data_type": "character",
                  "udt_name": "bpchar",
                  "character_maximum_length": 3,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "timezone",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "orders_count",
                  "ordinal_position": 5,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "gross_cents",
                  "ordinal_position": 6,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "refunded_cents",
                  "ordinal_position": 7,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "returned_cents",
                  "ordinal_position": 8,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "net_cents",
                  "ordinal_position": 9,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "commission_accrued_cents",
                  "ordinal_position": 10,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "commission_reversed_cents",
                  "ordinal_position": 11,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "commission_paid_cents",
                  "ordinal_position": 12,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 13,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "affiliate_professional_id",
                "currency_code",
                "day",
                "timezone"
              ],
              "foreign_keys": [
                {
                  "fk_name": "professional_metrics_daily_affiliate_professional_id_fkey",
                  "column_name": "affiliate_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "professional_metrics_daily_affiliate_day_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "day",
                    "affiliate_professional_id"
                  ],
                  "index_definition": "CREATE INDEX professional_metrics_daily_affiliate_day_idx ON analytics.professional_metrics_daily USING btree (affiliate_professional_id, day DESC)"
                },
                {
                  "index_name": "professional_metrics_daily_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "day",
                    "affiliate_professional_id",
                    "currency_code",
                    "timezone"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX professional_metrics_daily_pkey ON analytics.professional_metrics_daily USING btree (day, affiliate_professional_id, currency_code, timezone)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "professional_metrics_hourly",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "hour_start",
                  "ordinal_position": 1,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "affiliate_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 3,
                  "data_type": "character",
                  "udt_name": "bpchar",
                  "character_maximum_length": 3,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "timezone",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "orders_count",
                  "ordinal_position": 5,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "gross_cents",
                  "ordinal_position": 6,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "refunded_cents",
                  "ordinal_position": 7,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "returned_cents",
                  "ordinal_position": 8,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "net_cents",
                  "ordinal_position": 9,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "commission_accrued_cents",
                  "ordinal_position": 10,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "commission_reversed_cents",
                  "ordinal_position": 11,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "commission_paid_cents",
                  "ordinal_position": 12,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 13,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "affiliate_professional_id",
                "currency_code",
                "hour_start",
                "timezone"
              ],
              "foreign_keys": [
                {
                  "fk_name": "professional_metrics_hourly_affiliate_professional_id_fkey",
                  "column_name": "affiliate_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "professional_metrics_hourly_affiliate_hour_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "hour_start",
                    "affiliate_professional_id"
                  ],
                  "index_definition": "CREATE INDEX professional_metrics_hourly_affiliate_hour_idx ON analytics.professional_metrics_hourly USING btree (affiliate_professional_id, hour_start DESC)"
                },
                {
                  "index_name": "professional_metrics_hourly_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "hour_start",
                    "affiliate_professional_id",
                    "currency_code",
                    "timezone"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX professional_metrics_hourly_pkey ON analytics.professional_metrics_hourly USING btree (hour_start, affiliate_professional_id, currency_code, timezone)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "professional_product_daily",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "day",
                  "ordinal_position": 1,
                  "data_type": "date",
                  "udt_name": "date",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "affiliate_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_product_id",
                  "ordinal_position": 4,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "category",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "collection",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 7,
                  "data_type": "character",
                  "udt_name": "bpchar",
                  "character_maximum_length": 3,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "timezone",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "units_sold",
                  "ordinal_position": 9,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "orders_count",
                  "ordinal_position": 10,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "net_cents",
                  "ordinal_position": 11,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "commission_net_cents",
                  "ordinal_position": 12,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 13,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "affiliate_professional_id",
                "brand_product_id",
                "brand_professional_id",
                "currency_code",
                "day",
                "timezone"
              ],
              "foreign_keys": [
                {
                  "fk_name": "professional_product_daily_affiliate_professional_id_fkey",
                  "column_name": "affiliate_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "professional_product_daily_brand_product_id_fkey",
                  "column_name": "brand_product_id",
                  "foreign_schema": "retail",
                  "foreign_table": "brand_products",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "professional_product_daily_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "professional_product_daily_affiliate_day_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "day",
                    "affiliate_professional_id"
                  ],
                  "index_definition": "CREATE INDEX professional_product_daily_affiliate_day_idx ON analytics.professional_product_daily USING btree (affiliate_professional_id, day DESC)"
                },
                {
                  "index_name": "professional_product_daily_brand_day_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "day",
                    "brand_professional_id"
                  ],
                  "index_definition": "CREATE INDEX professional_product_daily_brand_day_idx ON analytics.professional_product_daily USING btree (brand_professional_id, day DESC)"
                },
                {
                  "index_name": "professional_product_daily_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "day",
                    "affiliate_professional_id",
                    "brand_professional_id",
                    "brand_product_id",
                    "currency_code",
                    "timezone"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX professional_product_daily_pkey ON analytics.professional_product_daily USING btree (day, affiliate_professional_id, brand_professional_id, brand_product_id, currency_code, timezone)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "site_metrics_daily",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "day",
                  "ordinal_position": 1,
                  "data_type": "date",
                  "udt_name": "date",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "site_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "timezone",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "visits_count",
                  "ordinal_position": 5,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "unique_visitors",
                  "ordinal_position": 6,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "clicks_count",
                  "ordinal_position": 7,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "unique_clickers",
                  "ordinal_position": 8,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 9,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "day",
                "professional_id",
                "site_id",
                "timezone"
              ],
              "foreign_keys": [
                {
                  "fk_name": "site_metrics_daily_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "site_metrics_daily_site_id_fkey",
                  "column_name": "site_id",
                  "foreign_schema": "core",
                  "foreign_table": "sites",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "site_metrics_daily_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "day",
                    "professional_id",
                    "site_id",
                    "timezone"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX site_metrics_daily_pkey ON analytics.site_metrics_daily USING btree (day, professional_id, site_id, timezone)"
                },
                {
                  "index_name": "site_metrics_daily_professional_day_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "day",
                    "professional_id"
                  ],
                  "index_definition": "CREATE INDEX site_metrics_daily_professional_day_idx ON analytics.site_metrics_daily USING btree (professional_id, day DESC)"
                },
                {
                  "index_name": "site_metrics_daily_site_day_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "day",
                    "site_id"
                  ],
                  "index_definition": "CREATE INDEX site_metrics_daily_site_day_idx ON analytics.site_metrics_daily USING btree (site_id, day DESC)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "site_metrics_hourly",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "hour_start",
                  "ordinal_position": 1,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "site_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "timezone",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "visits_count",
                  "ordinal_position": 5,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "unique_visitors",
                  "ordinal_position": 6,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "clicks_count",
                  "ordinal_position": 7,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "unique_clickers",
                  "ordinal_position": 8,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 9,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "hour_start",
                "professional_id",
                "site_id",
                "timezone"
              ],
              "foreign_keys": [
                {
                  "fk_name": "site_metrics_hourly_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "site_metrics_hourly_site_id_fkey",
                  "column_name": "site_id",
                  "foreign_schema": "core",
                  "foreign_table": "sites",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "site_metrics_hourly_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "hour_start",
                    "professional_id",
                    "site_id",
                    "timezone"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX site_metrics_hourly_pkey ON analytics.site_metrics_hourly USING btree (hour_start, professional_id, site_id, timezone)"
                },
                {
                  "index_name": "site_metrics_hourly_professional_hour_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "hour_start",
                    "professional_id"
                  ],
                  "index_definition": "CREATE INDEX site_metrics_hourly_professional_hour_idx ON analytics.site_metrics_hourly USING btree (professional_id, hour_start DESC)"
                },
                {
                  "index_name": "site_metrics_hourly_site_hour_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "hour_start",
                    "site_id"
                  ],
                  "index_definition": "CREATE INDEX site_metrics_hourly_site_hour_idx ON analytics.site_metrics_hourly USING btree (site_id, hour_start DESC)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "site_visits",
              "table_type": "BASE TABLE",
              "table_comment": "Page view analytics with device/country detection",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "site_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "occurred_at",
                  "ordinal_position": 4,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "session_id",
                  "ordinal_position": 5,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "visitor_id",
                  "ordinal_position": 6,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "ip_hash",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "user_agent",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "referrer",
                  "ordinal_position": 9,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "utm_source",
                  "ordinal_position": 10,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "utm_medium",
                  "ordinal_position": 11,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "utm_campaign",
                  "ordinal_position": 12,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 13,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "country_code",
                  "ordinal_position": 14,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "device_type",
                  "ordinal_position": 15,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "site_visits_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "site_visits_site_id_fkey",
                  "column_name": "site_id",
                  "foreign_schema": "core",
                  "foreign_table": "sites",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "analytics_site_visits_professional_occurred_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "occurred_at"
                  ],
                  "index_definition": "CREATE INDEX analytics_site_visits_professional_occurred_idx ON analytics.site_visits USING btree (professional_id, occurred_at)"
                },
                {
                  "index_name": "site_visits_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX site_visits_pkey ON analytics.site_visits USING btree (id)"
                },
                {
                  "index_name": "site_visits_pro_date_range_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "occurred_at",
                    "country_code",
                    "device_type"
                  ],
                  "index_definition": "CREATE INDEX site_visits_pro_date_range_idx ON analytics.site_visits USING btree (professional_id, occurred_at DESC) INCLUDE (country_code, device_type)"
                },
                {
                  "index_name": "site_visits_professional_time_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "occurred_at"
                  ],
                  "index_definition": "CREATE INDEX site_visits_professional_time_idx ON analytics.site_visits USING btree (professional_id, occurred_at)"
                },
                {
                  "index_name": "site_visits_site_time_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "site_id",
                    "occurred_at"
                  ],
                  "index_definition": "CREATE INDEX site_visits_site_time_idx ON analytics.site_visits USING btree (site_id, occurred_at)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": [
                {
                  "policy_name": "site_visits_anyone_insert_valid_site",
                  "command": "INSERT",
                  "is_permissive": true,
                  "using_expression": null,
                  "with_check_expression": "(EXISTS ( SELECT 1\n   FROM core.sites s\n  WHERE ((s.id = site_visits.site_id) AND (s.professional_id = site_visits.professional_id) AND (s.is_published = true))))"
                },
                {
                  "policy_name": "site_visits_staff_all",
                  "command": "ALL",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = ( SELECT ( SELECT auth.uid() AS uid) AS uid))))",
                  "with_check_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = ( SELECT ( SELECT auth.uid() AS uid) AS uid))))"
                }
              ]
            },
            {
              "table_name": "store_order_event_items",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "event_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "site_id",
                  "ordinal_position": 4,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 5,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_product_id",
                  "ordinal_position": 6,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "shopify_product_id",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "shopify_variant_id",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "title",
                  "ordinal_position": 9,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "variant_title",
                  "ordinal_position": 10,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "quantity",
                  "ordinal_position": 11,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "1",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "unit_price_cents",
                  "ordinal_position": 12,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "line_total_cents",
                  "ordinal_position": 13,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 14,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'AUD'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "metadata",
                  "ordinal_position": 15,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 16,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "store_order_event_items_brand_product_id_fkey",
                  "column_name": "brand_product_id",
                  "foreign_schema": "retail",
                  "foreign_table": "brand_products",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                },
                {
                  "fk_name": "store_order_event_items_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                },
                {
                  "fk_name": "store_order_event_items_event_id_fkey",
                  "column_name": "event_id",
                  "foreign_schema": "analytics",
                  "foreign_table": "store_order_events",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "store_order_event_items_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "store_order_event_items_site_id_fkey",
                  "column_name": "site_id",
                  "foreign_schema": "core",
                  "foreign_table": "sites",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "store_order_event_items_brand_occurred_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "created_at"
                  ],
                  "index_definition": "CREATE INDEX store_order_event_items_brand_occurred_idx ON analytics.store_order_event_items USING btree (brand_professional_id, created_at DESC)"
                },
                {
                  "index_name": "store_order_event_items_event_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "event_id"
                  ],
                  "index_definition": "CREATE INDEX store_order_event_items_event_idx ON analytics.store_order_event_items USING btree (event_id)"
                },
                {
                  "index_name": "store_order_event_items_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX store_order_event_items_pkey ON analytics.store_order_event_items USING btree (id)"
                },
                {
                  "index_name": "store_order_event_items_professional_occurred_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "created_at"
                  ],
                  "index_definition": "CREATE INDEX store_order_event_items_professional_occurred_idx ON analytics.store_order_event_items USING btree (professional_id, created_at DESC)"
                },
                {
                  "index_name": "store_order_event_items_shopify_product_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "shopify_product_id"
                  ],
                  "index_definition": "CREATE INDEX store_order_event_items_shopify_product_idx ON analytics.store_order_event_items USING btree (shopify_product_id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "store_order_events",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "site_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "occurred_at",
                  "ordinal_position": 4,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "source",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'site_checkout'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "subdomain",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "payment_method",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "customer_name",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "customer_email",
                  "ordinal_position": 9,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "customer_phone",
                  "ordinal_position": 10,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "order_name",
                  "ordinal_position": 11,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "draft_order_id",
                  "ordinal_position": 12,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "order_id",
                  "ordinal_position": 13,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 14,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'AUD'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "order_value_cents",
                  "ordinal_position": 15,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "line_item_count",
                  "ordinal_position": 16,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "raw_payload",
                  "ordinal_position": 17,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 18,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "store_order_events_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "store_order_events_site_id_fkey",
                  "column_name": "site_id",
                  "foreign_schema": "core",
                  "foreign_table": "sites",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "store_order_events_order_id_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "order_id"
                  ],
                  "index_definition": "CREATE INDEX store_order_events_order_id_idx ON analytics.store_order_events USING btree (order_id)"
                },
                {
                  "index_name": "store_order_events_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX store_order_events_pkey ON analytics.store_order_events USING btree (id)"
                },
                {
                  "index_name": "store_order_events_professional_occurred_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "occurred_at"
                  ],
                  "index_definition": "CREATE INDEX store_order_events_professional_occurred_idx ON analytics.store_order_events USING btree (professional_id, occurred_at DESC)"
                },
                {
                  "index_name": "store_order_events_site_occurred_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "site_id",
                    "occurred_at"
                  ],
                  "index_definition": "CREATE INDEX store_order_events_site_occurred_idx ON analytics.store_order_events USING btree (site_id, occurred_at DESC)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            }
          ],
          "enums": null,
          "functions": null
        },
        {
          "schema_name": "billing",
          "tables": [
            {
              "table_name": "plans",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "plan_key",
                  "ordinal_position": 2,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "name",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "stripe_price_id",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "is_active",
                  "ordinal_position": 5,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "true",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "sort_order",
                  "ordinal_position": 6,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "entitlements",
                  "ordinal_position": 7,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 8,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 9,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "description",
                  "ordinal_position": 10,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "price_cents",
                  "ordinal_position": 11,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Price in the smallest currency unit (e.g. cents)"
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 12,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'AUD'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "ISO 4217 currency code"
                },
                {
                  "column_name": "billing_interval",
                  "ordinal_position": 13,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'month'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Billing cadence: month | year"
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": null,
              "unique_constraints": [
                {
                  "uq_name": "plans_plan_key_key",
                  "columns": [
                    "plan_key"
                  ]
                },
                {
                  "uq_name": "plans_stripe_price_id_key",
                  "columns": [
                    "stripe_price_id"
                  ]
                }
              ],
              "indexes": [
                {
                  "index_name": "plans_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX plans_pkey ON billing.plans USING btree (id)"
                },
                {
                  "index_name": "plans_plan_key_key",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "plan_key"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX plans_plan_key_key ON billing.plans USING btree (plan_key)"
                },
                {
                  "index_name": "plans_stripe_price_id_key",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "stripe_price_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX plans_stripe_price_id_key ON billing.plans USING btree (stripe_price_id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": [
                {
                  "policy_name": "read active plans",
                  "command": "SELECT",
                  "is_permissive": true,
                  "using_expression": "(is_active = true)",
                  "with_check_expression": null
                }
              ]
            },
            {
              "table_name": "subscriptions",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "plan_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "provider",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'stripe'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "stripe_customer_id",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "stripe_subscription_id",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "status",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "current_period_end",
                  "ordinal_position": 8,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "cancel_at_period_end",
                  "ordinal_position": 9,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "false",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "trial_ends_at",
                  "ordinal_position": 10,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "ended_at",
                  "ordinal_position": 11,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "provider_payload",
                  "ordinal_position": 12,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 13,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 14,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "current_period_start",
                  "ordinal_position": 15,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "subscriptions_plan_id_fkey",
                  "column_name": "plan_id",
                  "foreign_schema": "billing",
                  "foreign_table": "plans",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "RESTRICT"
                },
                {
                  "fk_name": "subscriptions_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": [
                {
                  "uq_name": "subscriptions_stripe_subscription_id_key",
                  "columns": [
                    "stripe_subscription_id"
                  ]
                }
              ],
              "indexes": [
                {
                  "index_name": "billing_one_current_sub_per_professional",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX billing_one_current_sub_per_professional ON billing.subscriptions USING btree (professional_id) WHERE (ended_at IS NULL)"
                },
                {
                  "index_name": "billing_subscriptions_cancel_period_end_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "current_period_end"
                  ],
                  "index_definition": "CREATE INDEX billing_subscriptions_cancel_period_end_idx ON billing.subscriptions USING btree (current_period_end) WHERE ((cancel_at_period_end = true) AND (ended_at IS NULL))"
                },
                {
                  "index_name": "billing_subscriptions_plan_status_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "plan_id",
                    "status"
                  ],
                  "index_definition": "CREATE INDEX billing_subscriptions_plan_status_idx ON billing.subscriptions USING btree (plan_id, status) WHERE (ended_at IS NULL)"
                },
                {
                  "index_name": "billing_subscriptions_professional_id_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id"
                  ],
                  "index_definition": "CREATE INDEX billing_subscriptions_professional_id_idx ON billing.subscriptions USING btree (professional_id)"
                },
                {
                  "index_name": "billing_subscriptions_status_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "status"
                  ],
                  "index_definition": "CREATE INDEX billing_subscriptions_status_idx ON billing.subscriptions USING btree (status)"
                },
                {
                  "index_name": "billing_subscriptions_trial_ends_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "trial_ends_at"
                  ],
                  "index_definition": "CREATE INDEX billing_subscriptions_trial_ends_idx ON billing.subscriptions USING btree (trial_ends_at) WHERE ((status = ANY (ARRAY['trialing'::text, 'active'::text])) AND (trial_ends_at IS NOT NULL))"
                },
                {
                  "index_name": "subscriptions_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX subscriptions_pkey ON billing.subscriptions USING btree (id)"
                },
                {
                  "index_name": "subscriptions_stripe_subscription_id_key",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "stripe_subscription_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX subscriptions_stripe_subscription_id_key ON billing.subscriptions USING btree (stripe_subscription_id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": [
                {
                  "policy_name": "read own subscription",
                  "command": "SELECT",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.professionals p\n  WHERE ((p.id = subscriptions.professional_id) AND (p.auth_user_id = auth.uid()) AND (p.deleted_at IS NULL))))",
                  "with_check_expression": null
                }
              ]
            }
          ],
          "enums": null,
          "functions": [
            {
              "function_name": "set_updated_at",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nbegin\n  new.updated_at = now();\n  return new;\nend;\n",
              "language": "plpgsql"
            }
          ]
        },
        {
          "schema_name": "core",
          "tables": [
            {
              "table_name": "all_site_data",
              "table_type": "VIEW",
              "table_comment": "Site + professional aggregate payload for staff tooling (includes professional_type)",
              "columns": [
                {
                  "column_name": "site_id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "subdomain",
                  "ordinal_position": 2,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "is_published",
                  "ordinal_position": 3,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "site_settings",
                  "ordinal_position": 4,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "site_created_at",
                  "ordinal_position": 5,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "site_updated_at",
                  "ordinal_position": 6,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "theme_id",
                  "ordinal_position": 7,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "theme_key",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "theme_name",
                  "ordinal_position": 9,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "theme_config",
                  "ordinal_position": 10,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 11,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_handle",
                  "ordinal_position": 12,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_display_name",
                  "ordinal_position": 13,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_bio",
                  "ordinal_position": 14,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_location_street_address",
                  "ordinal_position": 15,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_location_city",
                  "ordinal_position": 16,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_location_state",
                  "ordinal_position": 17,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_location_postcode",
                  "ordinal_position": 18,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_location_country",
                  "ordinal_position": 19,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "blocks",
                  "ordinal_position": 20,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_type",
                  "ordinal_position": 21,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": null,
              "foreign_keys": null,
              "unique_constraints": null,
              "indexes": null,
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "blocks",
              "table_type": "BASE TABLE",
              "table_comment": "Polymorphic content blocks (links, sections) for sites",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "site_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "block_type",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'link'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Block type:  link, services, gallery, bio, etc."
                },
                {
                  "column_name": "title",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "url",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "icon_key",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "sort_order",
                  "ordinal_position": 8,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "is_active",
                  "ordinal_position": 9,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "true",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "settings",
                  "ordinal_position": 10,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 11,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 12,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "block_group",
                  "ordinal_position": 13,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'links'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Group type:  links or sections"
                },
                {
                  "column_name": "deleted_at",
                  "ordinal_position": 14,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "is_enabled",
                  "ordinal_position": 15,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "false",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Whether the section is configured/available. Separate from is_active (publicly visible on site)."
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "blocks_professional_fk",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "blocks_site_fk",
                  "column_name": "site_id",
                  "foreign_schema": "core",
                  "foreign_table": "sites",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "link_blocks_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "link_blocks_site_id_fkey",
                  "column_name": "site_id",
                  "foreign_schema": "core",
                  "foreign_table": "sites",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "blocks_links_site_group_sort_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "site_id",
                    "sort_order",
                    "block_group"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX blocks_links_site_group_sort_uq ON core.blocks USING btree (site_id, block_group, sort_order) WHERE ((block_group = 'links'::text) AND (deleted_at IS NULL))"
                },
                {
                  "index_name": "blocks_sections_site_group_sort_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "site_id",
                    "sort_order",
                    "block_group"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX blocks_sections_site_group_sort_uq ON core.blocks USING btree (site_id, block_group, sort_order) WHERE ((block_group = 'sections'::text) AND (deleted_at IS NULL))"
                },
                {
                  "index_name": "blocks_sections_site_group_type_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "site_id",
                    "block_type",
                    "block_group"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX blocks_sections_site_group_type_uq ON core.blocks USING btree (site_id, block_group, block_type) WHERE ((block_group = 'sections'::text) AND (deleted_at IS NULL))"
                },
                {
                  "index_name": "blocks_site_group_active_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "site_id",
                    "sort_order",
                    "block_group"
                  ],
                  "index_definition": "CREATE INDEX blocks_site_group_active_idx ON core.blocks USING btree (site_id, block_group, sort_order) WHERE ((deleted_at IS NULL) AND (is_active = true))"
                },
                {
                  "index_name": "blocks_site_type_active_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "site_id",
                    "block_type",
                    "sort_order"
                  ],
                  "index_definition": "CREATE INDEX blocks_site_type_active_idx ON core.blocks USING btree (site_id, block_type, sort_order) WHERE ((deleted_at IS NULL) AND (is_active = true))"
                },
                {
                  "index_name": "core_link_blocks_professional_sort_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "sort_order"
                  ],
                  "index_definition": "CREATE INDEX core_link_blocks_professional_sort_idx ON core.blocks USING btree (professional_id, sort_order)"
                },
                {
                  "index_name": "link_blocks_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX link_blocks_pkey ON core.blocks USING btree (id)"
                },
                {
                  "index_name": "link_blocks_pro_group_sort_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "sort_order",
                    "block_group"
                  ],
                  "index_definition": "CREATE INDEX link_blocks_pro_group_sort_idx ON core.blocks USING btree (professional_id, block_group, sort_order)"
                },
                {
                  "index_name": "link_blocks_professional_id_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id"
                  ],
                  "index_definition": "CREATE INDEX link_blocks_professional_id_idx ON core.blocks USING btree (professional_id)"
                },
                {
                  "index_name": "link_blocks_site_group_sort_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "site_id",
                    "sort_order",
                    "block_group"
                  ],
                  "index_definition": "CREATE INDEX link_blocks_site_group_sort_idx ON core.blocks USING btree (site_id, block_group, sort_order)"
                },
                {
                  "index_name": "link_blocks_site_id_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "site_id",
                    "sort_order"
                  ],
                  "index_definition": "CREATE INDEX link_blocks_site_id_idx ON core.blocks USING btree (site_id, sort_order)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": [
                {
                  "policy_name": "link_blocks_delete_staff_only",
                  "command": "DELETE",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid())))",
                  "with_check_expression": null
                },
                {
                  "policy_name": "link_blocks_insert_authenticated",
                  "command": "INSERT",
                  "is_permissive": true,
                  "using_expression": null,
                  "with_check_expression": "((EXISTS ( SELECT 1\n   FROM core.professionals p\n  WHERE ((p.id = blocks.professional_id) AND (p.auth_user_id = auth.uid()) AND (p.deleted_at IS NULL)))) OR (EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid()))))"
                },
                {
                  "policy_name": "link_blocks_public_read_active_published",
                  "command": "SELECT",
                  "is_permissive": true,
                  "using_expression": "((is_active = true) AND (EXISTS ( SELECT 1\n   FROM core.sites s\n  WHERE ((s.id = blocks.site_id) AND (s.is_published = true)))))",
                  "with_check_expression": null
                },
                {
                  "policy_name": "link_blocks_select_authenticated",
                  "command": "SELECT",
                  "is_permissive": true,
                  "using_expression": "(((is_active = true) AND (EXISTS ( SELECT 1\n   FROM core.sites s\n  WHERE ((s.id = blocks.site_id) AND (s.is_published = true))))) OR (EXISTS ( SELECT 1\n   FROM core.professionals p\n  WHERE ((p.id = blocks.professional_id) AND (p.auth_user_id = auth.uid()) AND (p.deleted_at IS NULL)))) OR (EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid()))))",
                  "with_check_expression": null
                },
                {
                  "policy_name": "link_blocks_update_authenticated",
                  "command": "UPDATE",
                  "is_permissive": true,
                  "using_expression": "((EXISTS ( SELECT 1\n   FROM core.professionals p\n  WHERE ((p.id = blocks.professional_id) AND (p.auth_user_id = auth.uid()) AND (p.deleted_at IS NULL)))) OR (EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid()))))",
                  "with_check_expression": "((EXISTS ( SELECT 1\n   FROM core.professionals p\n  WHERE ((p.id = blocks.professional_id) AND (p.auth_user_id = auth.uid()) AND (p.deleted_at IS NULL)))) OR (EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid()))))"
                }
              ]
            },
            {
              "table_name": "brand_affiliate_invites",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "token",
                  "ordinal_position": 3,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 80,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "status",
                  "ordinal_position": 4,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 24,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'pending'::character varying",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "invite_type",
                  "ordinal_position": 5,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 24,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'generic'::character varying",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "email",
                  "ordinal_position": 6,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 255,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "email_lc",
                  "ordinal_position": 7,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 255,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "phone",
                  "ordinal_position": 8,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 40,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "first_name",
                  "ordinal_position": 9,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 80,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "last_name",
                  "ordinal_position": 10,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 80,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "message",
                  "ordinal_position": 11,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "claimed_professional_id",
                  "ordinal_position": 12,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "accepted_at",
                  "ordinal_position": 13,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "expires_at",
                  "ordinal_position": 14,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 15,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 16,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_affiliate_invites_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "brand_affiliate_invites_claimed_professional_id_fkey",
                  "column_name": "claimed_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "brand_affiliate_invites_brand_status_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "status"
                  ],
                  "index_definition": "CREATE INDEX brand_affiliate_invites_brand_status_idx ON core.brand_affiliate_invites USING btree (brand_professional_id, status)"
                },
                {
                  "index_name": "brand_affiliate_invites_email_lc_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "email_lc"
                  ],
                  "index_definition": "CREATE INDEX brand_affiliate_invites_email_lc_idx ON core.brand_affiliate_invites USING btree (email_lc)"
                },
                {
                  "index_name": "brand_affiliate_invites_pending_brand_email_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "email_lc"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_affiliate_invites_pending_brand_email_uq ON core.brand_affiliate_invites USING btree (brand_professional_id, email_lc) WHERE (((status)::text = 'pending'::text) AND (email_lc IS NOT NULL))"
                },
                {
                  "index_name": "brand_affiliate_invites_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_affiliate_invites_pkey ON core.brand_affiliate_invites USING btree (id)"
                },
                {
                  "index_name": "brand_affiliate_invites_token_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "token"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_affiliate_invites_token_uq ON core.brand_affiliate_invites USING btree (token)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "brand_fonts",
              "table_type": "BASE TABLE",
              "table_comment": "Brand-managed fonts used for themed affiliate/professional sites. Active pointer per brand+slot with version history.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "slot",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'primary'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Font slot key. v1 supports only primary."
                },
                {
                  "column_name": "file_name",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "file_path",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "file_url",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "format",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'woff2'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "file_hash",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "File hash metadata (SHA-256 for uploads; deterministic fallback for backfilled rows)."
                },
                {
                  "column_name": "size_bytes",
                  "ordinal_position": 9,
                  "data_type": "bigint",
                  "udt_name": "int8",
                  "character_maximum_length": null,
                  "numeric_precision": 64,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "is_active",
                  "ordinal_position": 10,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "true",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 11,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 12,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "deleted_at",
                  "ordinal_position": 13,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_fonts_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "brand_fonts_active_brand_slot_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "slot"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_fonts_active_brand_slot_uq ON core.brand_fonts USING btree (brand_professional_id, slot) WHERE ((is_active = true) AND (deleted_at IS NULL))"
                },
                {
                  "index_name": "brand_fonts_brand_created_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "created_at"
                  ],
                  "index_definition": "CREATE INDEX brand_fonts_brand_created_idx ON core.brand_fonts USING btree (brand_professional_id, created_at DESC)"
                },
                {
                  "index_name": "brand_fonts_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_fonts_pkey ON core.brand_fonts USING btree (id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "brand_partner_links",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "affiliate_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "slot",
                  "ordinal_position": 4,
                  "data_type": "smallint",
                  "udt_name": "int2",
                  "character_maximum_length": null,
                  "numeric_precision": 16,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 5,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 6,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_partner_links_affiliate_professional_id_fkey",
                  "column_name": "affiliate_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "brand_partner_links_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "brand_partner_links_affiliate_brand_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "affiliate_professional_id",
                    "brand_professional_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_partner_links_affiliate_brand_uq ON core.brand_partner_links USING btree (affiliate_professional_id, brand_professional_id)"
                },
                {
                  "index_name": "brand_partner_links_affiliate_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "affiliate_professional_id"
                  ],
                  "index_definition": "CREATE INDEX brand_partner_links_affiliate_idx ON core.brand_partner_links USING btree (affiliate_professional_id)"
                },
                {
                  "index_name": "brand_partner_links_affiliate_slot_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "affiliate_professional_id",
                    "slot"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_partner_links_affiliate_slot_uq ON core.brand_partner_links USING btree (affiliate_professional_id, slot)"
                },
                {
                  "index_name": "brand_partner_links_brand_created_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "created_at"
                  ],
                  "index_definition": "CREATE INDEX brand_partner_links_brand_created_idx ON core.brand_partner_links USING btree (brand_professional_id, created_at DESC)"
                },
                {
                  "index_name": "brand_partner_links_brand_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id"
                  ],
                  "index_definition": "CREATE INDEX brand_partner_links_brand_idx ON core.brand_partner_links USING btree (brand_professional_id)"
                },
                {
                  "index_name": "brand_partner_links_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_partner_links_pkey ON core.brand_partner_links USING btree (id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "brand_profiles",
              "table_type": "BASE TABLE",
              "table_comment": "Brand-specific business fields (ABN, ACN, legal name, etc.)",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "abn",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Australian Business Number"
                },
                {
                  "column_name": "acn",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Australian Company Number"
                },
                {
                  "column_name": "legal_business_name",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "business_type",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "industries",
                  "ordinal_position": 7,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'[]'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "JSON array of industry strings"
                },
                {
                  "column_name": "estimated_annual_income",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "business_website",
                  "ordinal_position": 9,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 10,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 11,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "affiliate_visibility",
                  "ordinal_position": 12,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'invite_only'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Controls whether affiliates can discover and connect to this brand freely (public) or only via invitation (invite_only)."
                },
                {
                  "column_name": "brand_status",
                  "ordinal_position": 13,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'deactivated'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Operational status of the brand affiliate program. deactivated = no new connections, no product sales. Synced automatically from onboarding readiness."
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_profiles_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": [
                {
                  "uq_name": "brand_profiles_professional_id_key",
                  "columns": [
                    "professional_id"
                  ]
                }
              ],
              "indexes": [
                {
                  "index_name": "brand_profiles_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_profiles_pkey ON core.brand_profiles USING btree (id)"
                },
                {
                  "index_name": "brand_profiles_professional_id_key",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_profiles_professional_id_key ON core.brand_profiles USING btree (professional_id)"
                },
                {
                  "index_name": "idx_brand_profiles_professional_id",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id"
                  ],
                  "index_definition": "CREATE INDEX idx_brand_profiles_professional_id ON core.brand_profiles USING btree (professional_id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "comet_staff",
              "table_type": "BASE TABLE",
              "table_comment": "Internal staff with role-based access (support/admin)",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "auth_user_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "role",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'support'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 4,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 5,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "primary_email",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "name",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "phone",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": null,
              "unique_constraints": [
                {
                  "uq_name": "comet_staff_Primary Email_key",
                  "columns": [
                    "primary_email"
                  ]
                },
                {
                  "uq_name": "comet_staff_auth_user_id_key",
                  "columns": [
                    "auth_user_id"
                  ]
                }
              ],
              "indexes": [
                {
                  "index_name": "comet_staff_Primary Email_key",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "primary_email"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX \"comet_staff_Primary Email_key\" ON core.comet_staff USING btree (primary_email)"
                },
                {
                  "index_name": "comet_staff_auth_user_id_key",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "auth_user_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX comet_staff_auth_user_id_key ON core.comet_staff USING btree (auth_user_id)"
                },
                {
                  "index_name": "comet_staff_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX comet_staff_pkey ON core.comet_staff USING btree (id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": true
              },
              "rls_policies": [
                {
                  "policy_name": "comet_staff_delete_admin",
                  "command": "DELETE",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE ((cs.auth_user_id = ( SELECT auth.uid() AS uid)) AND (cs.role = 'admin'::text))))",
                  "with_check_expression": null
                },
                {
                  "policy_name": "comet_staff_insert_admin",
                  "command": "INSERT",
                  "is_permissive": true,
                  "using_expression": null,
                  "with_check_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE ((cs.auth_user_id = ( SELECT auth.uid() AS uid)) AND (cs.role = 'admin'::text))))"
                },
                {
                  "policy_name": "comet_staff_select_authenticated",
                  "command": "SELECT",
                  "is_permissive": true,
                  "using_expression": "((auth_user_id = ( SELECT auth.uid() AS uid)) OR (EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE ((cs.auth_user_id = ( SELECT auth.uid() AS uid)) AND (cs.role = 'admin'::text)))))",
                  "with_check_expression": null
                },
                {
                  "policy_name": "comet_staff_update_authenticated",
                  "command": "UPDATE",
                  "is_permissive": true,
                  "using_expression": "((auth_user_id = ( SELECT auth.uid() AS uid)) OR (EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE ((cs.auth_user_id = ( SELECT auth.uid() AS uid)) AND (cs.role = 'admin'::text)))))",
                  "with_check_expression": "((auth_user_id = ( SELECT auth.uid() AS uid)) OR (EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE ((cs.auth_user_id = ( SELECT auth.uid() AS uid)) AND (cs.role = 'admin'::text)))))"
                }
              ]
            },
            {
              "table_name": "customers",
              "table_type": "BASE TABLE",
              "table_comment": "Customer contacts managed by professionals (soft deletes enabled)",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "email",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "phone",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "full_name",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "source",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "notes",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "external_id",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 9,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 10,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "deleted_at",
                  "ordinal_position": 11,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Soft delete timestamp (NULL = active)"
                },
                {
                  "column_name": "marketing_opt_in_cached",
                  "ordinal_position": 12,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": "true",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Cache of EmailSubscription status for this customer (true=subscribed, false=unsubscribed, NULL=unknown). Source of truth is EmailSubscription.status"
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "customers_professional_fk",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "customers_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "customers_marketing_opt_in_cached_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "marketing_opt_in_cached"
                  ],
                  "index_definition": "CREATE INDEX customers_marketing_opt_in_cached_idx ON core.customers USING btree (professional_id, marketing_opt_in_cached) WHERE (marketing_opt_in_cached IS NOT NULL)"
                },
                {
                  "index_name": "customers_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX customers_pkey ON core.customers USING btree (id)"
                },
                {
                  "index_name": "customers_professional_deleted_at_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "deleted_at"
                  ],
                  "index_definition": "CREATE INDEX customers_professional_deleted_at_idx ON core.customers USING btree (professional_id, deleted_at)"
                },
                {
                  "index_name": "customers_professional_email_search_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id"
                  ],
                  "index_definition": "CREATE INDEX customers_professional_email_search_idx ON core.customers USING btree (professional_id, lower(email)) WHERE ((email IS NOT NULL) AND (deleted_at IS NULL))"
                },
                {
                  "index_name": "customers_professional_email_unique",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX customers_professional_email_unique ON core.customers USING btree (professional_id, lower(email)) WHERE (email IS NOT NULL)"
                },
                {
                  "index_name": "customers_professional_id_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id"
                  ],
                  "index_definition": "CREATE INDEX customers_professional_id_idx ON core.customers USING btree (professional_id)"
                },
                {
                  "index_name": "customers_professional_name_search_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id"
                  ],
                  "index_definition": "CREATE INDEX customers_professional_name_search_idx ON core.customers USING btree (professional_id, lower(full_name)) WHERE ((full_name IS NOT NULL) AND (deleted_at IS NULL))"
                },
                {
                  "index_name": "customers_professional_phone_search_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "phone"
                  ],
                  "index_definition": "CREATE INDEX customers_professional_phone_search_idx ON core.customers USING btree (professional_id, phone) WHERE ((phone IS NOT NULL) AND (deleted_at IS NULL))"
                },
                {
                  "index_name": "customers_professional_phone_unique",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "phone"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX customers_professional_phone_unique ON core.customers USING btree (professional_id, phone) WHERE (phone IS NOT NULL)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": [
                {
                  "policy_name": "customers_all_authenticated",
                  "command": "ALL",
                  "is_permissive": true,
                  "using_expression": "((EXISTS ( SELECT 1\n   FROM core.professionals p\n  WHERE ((p.id = customers.professional_id) AND (p.auth_user_id = auth.uid()) AND (p.deleted_at IS NULL)))) OR (EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid()))))",
                  "with_check_expression": "((EXISTS ( SELECT 1\n   FROM core.professionals p\n  WHERE ((p.id = customers.professional_id) AND (p.auth_user_id = auth.uid()) AND (p.deleted_at IS NULL)))) OR (EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid()))))"
                }
              ]
            },
            {
              "table_name": "email_subscriptions",
              "table_type": "BASE TABLE",
              "table_comment": "Email subscription lists (marketing, comet_updates)",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "list_key",
                  "ordinal_position": 3,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 50,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'marketing'::character varying",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "List identifier:  marketing, comet_updates"
                },
                {
                  "column_name": "email",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "full_name",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "status",
                  "ordinal_position": 6,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 20,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'subscribed'::character varying",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Subscription status:  subscribed, unsubscribed, bounced, complained"
                },
                {
                  "column_name": "subscribed_at",
                  "ordinal_position": 7,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "unsubscribed_at",
                  "ordinal_position": 8,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "unsubscribe_token",
                  "ordinal_position": 9,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 80,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "consent_source",
                  "ordinal_position": 10,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 50,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "consent_ip_hash",
                  "ordinal_position": 11,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "consent_user_agent",
                  "ordinal_position": 12,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 13,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 14,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "email_lc",
                  "ordinal_position": 15,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "qr_slug",
                  "ordinal_position": 16,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "email_subscriptions_professional_fk",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "email_subscriptions_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": [
                {
                  "uq_name": "email_subscriptions_qr_slug_key",
                  "columns": [
                    "qr_slug"
                  ]
                }
              ],
              "indexes": [
                {
                  "index_name": "email_subs_global_list_status_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "list_key",
                    "status"
                  ],
                  "index_definition": "CREATE INDEX email_subs_global_list_status_idx ON core.email_subscriptions USING btree (list_key, status) WHERE (professional_id IS NULL)"
                },
                {
                  "index_name": "email_subs_pro_list_status_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "list_key",
                    "status"
                  ],
                  "index_definition": "CREATE INDEX email_subs_pro_list_status_idx ON core.email_subscriptions USING btree (professional_id, list_key, status) WHERE (professional_id IS NOT NULL)"
                },
                {
                  "index_name": "email_subscriptions_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX email_subscriptions_pkey ON core.email_subscriptions USING btree (id)"
                },
                {
                  "index_name": "email_subscriptions_qr_slug_key",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "qr_slug"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX email_subscriptions_qr_slug_key ON core.email_subscriptions USING btree (qr_slug)"
                },
                {
                  "index_name": "email_subscriptions_unique_global_list_email_lc",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "list_key",
                    "email_lc"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX email_subscriptions_unique_global_list_email_lc ON core.email_subscriptions USING btree (list_key, email_lc) WHERE (professional_id IS NULL)"
                },
                {
                  "index_name": "email_subscriptions_unique_pro_list_email_lc",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "list_key",
                    "email_lc"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX email_subscriptions_unique_pro_list_email_lc ON core.email_subscriptions USING btree (professional_id, list_key, email_lc) WHERE (professional_id IS NOT NULL)"
                },
                {
                  "index_name": "email_subscriptions_unsubscribe_token_unique",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "unsubscribe_token"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX email_subscriptions_unsubscribe_token_unique ON core.email_subscriptions USING btree (unsubscribe_token)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": [
                {
                  "policy_name": "email_subs_pro_all",
                  "command": "ALL",
                  "is_permissive": true,
                  "using_expression": "(professional_id = ( SELECT professionals.id\n   FROM core.professionals\n  WHERE ((professionals.auth_user_id = auth.uid()) AND (professionals.deleted_at IS NULL))))",
                  "with_check_expression": "(professional_id = ( SELECT professionals.id\n   FROM core.professionals\n  WHERE ((professionals.auth_user_id = auth.uid()) AND (professionals.deleted_at IS NULL))))"
                },
                {
                  "policy_name": "email_subs_public_insert",
                  "command": "INSERT",
                  "is_permissive": true,
                  "using_expression": null,
                  "with_check_expression": "true"
                },
                {
                  "policy_name": "email_subs_public_unsubscribe",
                  "command": "SELECT",
                  "is_permissive": true,
                  "using_expression": "(unsubscribe_token IS NOT NULL)",
                  "with_check_expression": null
                },
                {
                  "policy_name": "email_subs_staff_all",
                  "command": "ALL",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff\n  WHERE (comet_staff.auth_user_id = auth.uid())))",
                  "with_check_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff\n  WHERE (comet_staff.auth_user_id = auth.uid())))"
                }
              ]
            },
            {
              "table_name": "enterprise_brand_links",
              "table_type": "BASE TABLE",
              "table_comment": "Links distributor enterprises to managed brand professional accounts.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "enterprise_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "role",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'manager'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "status",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'active'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 6,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 7,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "enterprise_brand_links_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "enterprise_brand_links_enterprise_id_fkey",
                  "column_name": "enterprise_id",
                  "foreign_schema": "core",
                  "foreign_table": "enterprises",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "ebl_brand_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id"
                  ],
                  "index_definition": "CREATE INDEX ebl_brand_idx ON core.enterprise_brand_links USING btree (brand_professional_id)"
                },
                {
                  "index_name": "ebl_enterprise_brand_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "enterprise_id",
                    "brand_professional_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX ebl_enterprise_brand_uq ON core.enterprise_brand_links USING btree (enterprise_id, brand_professional_id)"
                },
                {
                  "index_name": "ebl_enterprise_status_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "enterprise_id",
                    "status"
                  ],
                  "index_definition": "CREATE INDEX ebl_enterprise_status_idx ON core.enterprise_brand_links USING btree (enterprise_id, status)"
                },
                {
                  "index_name": "enterprise_brand_links_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX enterprise_brand_links_pkey ON core.enterprise_brand_links USING btree (id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "enterprises",
              "table_type": "BASE TABLE",
              "table_comment": "Top-level business entities (promoters, salons, barbershops).",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "auth_user_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Optional primary auth owner for self-service enterprise account endpoints."
                },
                {
                  "column_name": "name",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "handle",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "primary_email",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "phone",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "public_contact_email",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "public_contact_number",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "country_code",
                  "ordinal_position": 9,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "timezone",
                  "ordinal_position": 10,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "location_street_address",
                  "ordinal_position": 11,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "location_city",
                  "ordinal_position": 12,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "location_state",
                  "ordinal_position": 13,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "location_postcode",
                  "ordinal_position": 14,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "location_country",
                  "ordinal_position": 15,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "enterprise_type",
                  "ordinal_position": 16,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Top-level business entity category (promoter, salon, barbershop, distributor)."
                },
                {
                  "column_name": "status",
                  "ordinal_position": 17,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'active'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "subscription_tier",
                  "ordinal_position": 18,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Enterprise subscription tier/plan key."
                },
                {
                  "column_name": "metadata",
                  "ordinal_position": 19,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Flexible metadata for enterprise-specific integrations and context."
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 20,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 21,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "deleted_at",
                  "ordinal_position": 22,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": null,
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "enterprises_auth_user_active_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "auth_user_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX enterprises_auth_user_active_uq ON core.enterprises USING btree (auth_user_id) WHERE ((auth_user_id IS NOT NULL) AND (deleted_at IS NULL))"
                },
                {
                  "index_name": "enterprises_handle_lc_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [],
                  "index_definition": "CREATE UNIQUE INDEX enterprises_handle_lc_uq ON core.enterprises USING btree (lower(handle)) WHERE ((handle IS NOT NULL) AND (deleted_at IS NULL))"
                },
                {
                  "index_name": "enterprises_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX enterprises_pkey ON core.enterprises USING btree (id)"
                },
                {
                  "index_name": "enterprises_type_status_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "enterprise_type",
                    "status"
                  ],
                  "index_definition": "CREATE INDEX enterprises_type_status_idx ON core.enterprises USING btree (enterprise_type, status) WHERE (deleted_at IS NULL)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "influencer_promoter_contracts",
              "table_type": "BASE TABLE",
              "table_comment": "Contract history linking influencers to promoter enterprises.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "influencer_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "promoter_enterprise_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "status",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'active'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "exclusive",
                  "ordinal_position": 5,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "true",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "starts_at",
                  "ordinal_position": 6,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "ends_at",
                  "ordinal_position": 7,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "notes",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "metadata",
                  "ordinal_position": 9,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 10,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 11,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "influencer_promoter_contracts_influencer_professional_id_fkey",
                  "column_name": "influencer_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "influencer_promoter_contracts_promoter_enterprise_id_fkey",
                  "column_name": "promoter_enterprise_id",
                  "foreign_schema": "core",
                  "foreign_table": "enterprises",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "influencer_promoter_contracts_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX influencer_promoter_contracts_pkey ON core.influencer_promoter_contracts USING btree (id)"
                },
                {
                  "index_name": "ipc_influencer_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "influencer_professional_id",
                    "starts_at"
                  ],
                  "index_definition": "CREATE INDEX ipc_influencer_idx ON core.influencer_promoter_contracts USING btree (influencer_professional_id, starts_at DESC)"
                },
                {
                  "index_name": "ipc_one_active_exclusive_contract_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "influencer_professional_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX ipc_one_active_exclusive_contract_uq ON core.influencer_promoter_contracts USING btree (influencer_professional_id) WHERE ((exclusive = true) AND (status = 'active'::text) AND (ends_at IS NULL))"
                },
                {
                  "index_name": "ipc_promoter_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "promoter_enterprise_id",
                    "starts_at"
                  ],
                  "index_definition": "CREATE INDEX ipc_promoter_idx ON core.influencer_promoter_contracts USING btree (promoter_enterprise_id, starts_at DESC)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "media_variants",
              "table_type": "BASE TABLE",
              "table_comment": "Video artifact variants (MP4, HLS playlists, poster) for each site_image with media_type=video",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "media_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "variant_key",
                  "ordinal_position": 3,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 40,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Logical tier: optimized | maximized | adaptive | poster"
                },
                {
                  "column_name": "artifact_type",
                  "ordinal_position": 4,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 20,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Physical format: mp4 | hls_playlist | poster"
                },
                {
                  "column_name": "disk",
                  "ordinal_position": 5,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 40,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'media'::character varying",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "path",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Storage path on the media disk (not a public URL)"
                },
                {
                  "column_name": "mime",
                  "ordinal_position": 7,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 100,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "width",
                  "ordinal_position": 8,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "height",
                  "ordinal_position": 9,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "bitrate_kbps",
                  "ordinal_position": 10,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "file_size_bytes",
                  "ordinal_position": 11,
                  "data_type": "bigint",
                  "udt_name": "int8",
                  "character_maximum_length": null,
                  "numeric_precision": 64,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "duration_ms",
                  "ordinal_position": 12,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "metadata",
                  "ordinal_position": 13,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Arbitrary codec/probe metadata; not included in public payload"
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 14,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 15,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "content_hash",
                  "ordinal_position": 16,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 16,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "SHA-256 hash prefix (16 chars) used in content-addressed image variant paths"
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "media_variants_media_id_fkey",
                  "column_name": "media_id",
                  "foreign_schema": "core",
                  "foreign_table": "site_media",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "media_variants_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX media_variants_pkey ON core.media_variants USING btree (id)"
                },
                {
                  "index_name": "mv_media_artifact_type",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "media_id",
                    "artifact_type"
                  ],
                  "index_definition": "CREATE INDEX mv_media_artifact_type ON core.media_variants USING btree (media_id, artifact_type)"
                },
                {
                  "index_name": "mv_media_id",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "media_id"
                  ],
                  "index_definition": "CREATE INDEX mv_media_id ON core.media_variants USING btree (media_id)"
                },
                {
                  "index_name": "mv_media_variant_artifact",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "media_id",
                    "variant_key",
                    "artifact_type"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX mv_media_variant_artifact ON core.media_variants USING btree (media_id, variant_key, artifact_type)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "notification_receipts",
              "table_type": "BASE TABLE",
              "table_comment": "Tracks read/dismiss status per professional per notification",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "notification_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "read_at",
                  "ordinal_position": 4,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "dismissed_at",
                  "ordinal_position": 5,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 6,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 7,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "notification_receipts_notification_id_fkey",
                  "column_name": "notification_id",
                  "foreign_schema": "core",
                  "foreign_table": "notifications",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "notification_receipts_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "receipts_notification_fk",
                  "column_name": "notification_id",
                  "foreign_schema": "core",
                  "foreign_table": "notifications",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "receipts_professional_fk",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "notification_receipts_notification_professional_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "notification_id",
                    "professional_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX notification_receipts_notification_professional_uq ON core.notification_receipts USING btree (notification_id, professional_id)"
                },
                {
                  "index_name": "notification_receipts_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX notification_receipts_pkey ON core.notification_receipts USING btree (id)"
                },
                {
                  "index_name": "receipts_pro_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "updated_at"
                  ],
                  "index_definition": "CREATE INDEX receipts_pro_idx ON core.notification_receipts USING btree (professional_id, updated_at DESC)"
                },
                {
                  "index_name": "receipts_unread_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "notification_id",
                    "professional_id"
                  ],
                  "index_definition": "CREATE INDEX receipts_unread_idx ON core.notification_receipts USING btree (professional_id, notification_id) WHERE ((read_at IS NULL) AND (dismissed_at IS NULL))"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": [
                {
                  "policy_name": "notification_receipts_admin_write",
                  "command": "ALL",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE ((cs.auth_user_id = auth.uid()) AND (cs.role = 'admin'::text))))",
                  "with_check_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE ((cs.auth_user_id = auth.uid()) AND (cs.role = 'admin'::text))))"
                },
                {
                  "policy_name": "notification_receipts_pro_all",
                  "command": "ALL",
                  "is_permissive": true,
                  "using_expression": "(professional_id IN ( SELECT p.id\n   FROM core.professionals p\n  WHERE ((p.auth_user_id = auth.uid()) AND (p.deleted_at IS NULL))))",
                  "with_check_expression": "(professional_id IN ( SELECT p.id\n   FROM core.professionals p\n  WHERE ((p.auth_user_id = auth.uid()) AND (p.deleted_at IS NULL))))"
                },
                {
                  "policy_name": "notification_receipts_staff_select",
                  "command": "SELECT",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid())))",
                  "with_check_expression": null
                }
              ]
            },
            {
              "table_name": "notifications",
              "table_type": "BASE TABLE",
              "table_comment": "In-app notifications (broadcast or targeted to professional)",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "type",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "title",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "body",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "cta_url",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "severity",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'info'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Notification severity: info, warning, critical"
                },
                {
                  "column_name": "starts_at",
                  "ordinal_position": 8,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "ends_at",
                  "ordinal_position": 9,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 10,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 11,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "primary_action_label",
                  "ordinal_position": 12,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 255,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "secondary_action_label",
                  "ordinal_position": 13,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 255,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "secondary_action_url",
                  "ordinal_position": 14,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "notifications_professional_fk",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "notifications_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "notifications_broadcast_active_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "created_at"
                  ],
                  "index_definition": "CREATE INDEX notifications_broadcast_active_idx ON core.notifications USING btree (created_at DESC) WHERE (professional_id IS NULL)"
                },
                {
                  "index_name": "notifications_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX notifications_pkey ON core.notifications USING btree (id)"
                },
                {
                  "index_name": "notifications_pro_active_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "created_at"
                  ],
                  "index_definition": "CREATE INDEX notifications_pro_active_idx ON core.notifications USING btree (professional_id, created_at DESC) WHERE (professional_id IS NOT NULL)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": [
                {
                  "policy_name": "notifications_select_pro",
                  "command": "SELECT",
                  "is_permissive": true,
                  "using_expression": "((professional_id IS NULL) OR (professional_id IN ( SELECT p.id\n   FROM core.professionals p\n  WHERE ((p.auth_user_id = auth.uid()) AND (p.deleted_at IS NULL)))))",
                  "with_check_expression": null
                },
                {
                  "policy_name": "notifications_select_staff",
                  "command": "SELECT",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid())))",
                  "with_check_expression": null
                },
                {
                  "policy_name": "notifications_write_admin",
                  "command": "ALL",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE ((cs.auth_user_id = auth.uid()) AND (cs.role = 'admin'::text))))",
                  "with_check_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE ((cs.auth_user_id = auth.uid()) AND (cs.role = 'admin'::text))))"
                }
              ]
            },
            {
              "table_name": "professional_confirmation_preferences",
              "table_type": "BASE TABLE",
              "table_comment": "Per-professional overrides for destructive-action confirmation dialogs.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "action_key",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Action identifier (for example: delete_customer, delete_media, unselect_product)."
                },
                {
                  "column_name": "skip_confirmation",
                  "ordinal_position": 4,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "false",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "When true, frontend can skip confirmation modal for this action."
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 5,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 6,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "professional_confirmation_preferences_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": [
                {
                  "uq_name": "professional_confirmation_preferences_professional_action_uq",
                  "columns": [
                    "action_key",
                    "professional_id"
                  ]
                }
              ],
              "indexes": [
                {
                  "index_name": "professional_confirmation_preferences_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX professional_confirmation_preferences_pkey ON core.professional_confirmation_preferences USING btree (id)"
                },
                {
                  "index_name": "professional_confirmation_preferences_professional_action_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "action_key"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX professional_confirmation_preferences_professional_action_uq ON core.professional_confirmation_preferences USING btree (professional_id, action_key)"
                },
                {
                  "index_name": "professional_confirmation_preferences_professional_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id"
                  ],
                  "index_definition": "CREATE INDEX professional_confirmation_preferences_professional_idx ON core.professional_confirmation_preferences USING btree (professional_id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "professional_enterprise_memberships",
              "table_type": "BASE TABLE",
              "table_comment": "Time-bound relationship between professionals and enterprises.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "enterprise_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "relationship_type",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'member'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "is_primary",
                  "ordinal_position": 5,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "false",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "starts_at",
                  "ordinal_position": 6,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "ends_at",
                  "ordinal_position": 7,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "metadata",
                  "ordinal_position": 8,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 9,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 10,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "professional_enterprise_memberships_enterprise_id_fkey",
                  "column_name": "enterprise_id",
                  "foreign_schema": "core",
                  "foreign_table": "enterprises",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "professional_enterprise_memberships_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "pem_enterprise_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "enterprise_id",
                    "starts_at"
                  ],
                  "index_definition": "CREATE INDEX pem_enterprise_idx ON core.professional_enterprise_memberships USING btree (enterprise_id, starts_at DESC)"
                },
                {
                  "index_name": "pem_professional_enterprise_active_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "enterprise_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX pem_professional_enterprise_active_uq ON core.professional_enterprise_memberships USING btree (professional_id, enterprise_id) WHERE (ends_at IS NULL)"
                },
                {
                  "index_name": "pem_professional_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "starts_at"
                  ],
                  "index_definition": "CREATE INDEX pem_professional_idx ON core.professional_enterprise_memberships USING btree (professional_id, starts_at DESC)"
                },
                {
                  "index_name": "pem_professional_primary_active_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX pem_professional_primary_active_uq ON core.professional_enterprise_memberships USING btree (professional_id) WHERE ((is_primary = true) AND (ends_at IS NULL))"
                },
                {
                  "index_name": "professional_enterprise_memberships_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX professional_enterprise_memberships_pkey ON core.professional_enterprise_memberships USING btree (id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "professional_integrations",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "provider",
                  "ordinal_position": 3,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 64,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "external_account_id",
                  "ordinal_position": 4,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 255,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "access_token",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "refresh_token",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "expires_at",
                  "ordinal_position": 7,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "catalog_latest_time",
                  "ordinal_position": 8,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "last_catalog_sync_at",
                  "ordinal_position": 9,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "last_catalog_sync_error",
                  "ordinal_position": 10,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "provider_metadata",
                  "ordinal_position": 11,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 12,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 13,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "shopify_shop_domain",
                  "ordinal_position": 14,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "professional_integrations_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "professional_integrations_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX professional_integrations_pkey ON core.professional_integrations USING btree (id)"
                },
                {
                  "index_name": "professional_integrations_professional_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id"
                  ],
                  "index_definition": "CREATE INDEX professional_integrations_professional_idx ON core.professional_integrations USING btree (professional_id)"
                },
                {
                  "index_name": "professional_integrations_professional_provider_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "provider"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX professional_integrations_professional_provider_uq ON core.professional_integrations USING btree (professional_id, provider)"
                },
                {
                  "index_name": "professional_integrations_provider_account_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "provider",
                    "external_account_id"
                  ],
                  "index_definition": "CREATE INDEX professional_integrations_provider_account_idx ON core.professional_integrations USING btree (provider, external_account_id)"
                },
                {
                  "index_name": "professional_integrations_shopify_domain_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "shopify_shop_domain"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX professional_integrations_shopify_domain_uq ON core.professional_integrations USING btree (shopify_shop_domain) WHERE (shopify_shop_domain IS NOT NULL)"
                },
                {
                  "index_name": "professional_integrations_shopify_shop_domain_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "provider"
                  ],
                  "index_definition": "CREATE INDEX professional_integrations_shopify_shop_domain_idx ON core.professional_integrations USING btree (provider, lower((provider_metadata ->> 'shop_domain'::text))) WHERE ((provider)::text = 'shopify'::text)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "professional_legal_contents",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "professional_id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "generated_privacy_policy",
                  "ordinal_position": 2,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "''::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "manual_privacy_policy",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "active_privacy_source",
                  "ordinal_position": 4,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 16,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'templated'::character varying",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "generated_terms_and_conditions",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "''::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "manual_terms_and_conditions",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "active_terms_source",
                  "ordinal_position": 7,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 16,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'templated'::character varying",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "template_variables",
                  "ordinal_position": 8,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "generated_at",
                  "ordinal_position": 9,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 10,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 11,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "professional_id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "professional_legal_contents_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "professional_legal_contents_generated_at_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "generated_at"
                  ],
                  "index_definition": "CREATE INDEX professional_legal_contents_generated_at_idx ON core.professional_legal_contents USING btree (generated_at)"
                },
                {
                  "index_name": "professional_legal_contents_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "professional_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX professional_legal_contents_pkey ON core.professional_legal_contents USING btree (professional_id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": [
                {
                  "policy_name": "legal_contents_pro_all",
                  "command": "ALL",
                  "is_permissive": true,
                  "using_expression": "(professional_id = ( SELECT professionals.id\n   FROM core.professionals\n  WHERE ((professionals.auth_user_id = auth.uid()) AND (professionals.deleted_at IS NULL))))",
                  "with_check_expression": "(professional_id = ( SELECT professionals.id\n   FROM core.professionals\n  WHERE ((professionals.auth_user_id = auth.uid()) AND (professionals.deleted_at IS NULL))))"
                },
                {
                  "policy_name": "legal_contents_staff_all",
                  "command": "ALL",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid())))",
                  "with_check_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid())))"
                }
              ]
            },
            {
              "table_name": "professionals",
              "table_type": "BASE TABLE",
              "table_comment": "Professional user profiles with unique handles and QR codes",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "auth_user_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "handle",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "display_name",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "bio",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "country_code",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "timezone",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "status",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'active'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "onboarding_step",
                  "ordinal_position": 9,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 10,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 11,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "phone",
                  "ordinal_position": 12,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "primary_email",
                  "ordinal_position": 13,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "first_name",
                  "ordinal_position": 14,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "last_name",
                  "ordinal_position": 15,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "public_contact_number",
                  "ordinal_position": 16,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "public_contact_email",
                  "ordinal_position": 17,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "location_street_address",
                  "ordinal_position": 22,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "location_postcode",
                  "ordinal_position": 23,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "location_city",
                  "ordinal_position": 24,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "location_state",
                  "ordinal_position": 25,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "location_country",
                  "ordinal_position": 26,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "handle_lc",
                  "ordinal_position": 27,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Lowercase version of handle for case-insensitive uniqueness"
                },
                {
                  "column_name": "qr_slug",
                  "ordinal_position": 28,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Unique slug for QR code generation (format: handle-random6)"
                },
                {
                  "column_name": "deleted_at",
                  "ordinal_position": 29,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_type",
                  "ordinal_position": 44,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'barber'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Professional business type/category (professional, influencer, barber, hairdresser, ambassador, promoter, brand, barbershop, salon)"
                },
                {
                  "column_name": "primary_enterprise_id",
                  "ordinal_position": 45,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Optional convenience FK to a professional's primary enterprise. Membership table remains source of truth."
                },
                {
                  "column_name": "stripe_connect_account_id",
                  "ordinal_position": 46,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "stripe_connect_status",
                  "ordinal_position": 47,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'not_connected'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "stripe_customer_id",
                  "ordinal_position": 48,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "stripe_payment_method_id",
                  "ordinal_position": 49,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "stripe_commission_funding_mode",
                  "ordinal_position": 50,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'auto_charge'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "stripe_manual_balance_cents",
                  "ordinal_position": 51,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "stripe_manual_balance_currency",
                  "ordinal_position": 52,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'AUD'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "professionals_primary_enterprise_id_fkey",
                  "column_name": "primary_enterprise_id",
                  "foreign_schema": "core",
                  "foreign_table": "enterprises",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "core_professionals_handle_lc_unique",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "handle_lc"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX core_professionals_handle_lc_unique ON core.professionals USING btree (handle_lc) WHERE (deleted_at IS NULL)"
                },
                {
                  "index_name": "idx_professionals_stripe_connect_account",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "stripe_connect_account_id"
                  ],
                  "index_definition": "CREATE INDEX idx_professionals_stripe_connect_account ON core.professionals USING btree (stripe_connect_account_id) WHERE (stripe_connect_account_id IS NOT NULL)"
                },
                {
                  "index_name": "idx_professionals_stripe_customer",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "stripe_customer_id"
                  ],
                  "index_definition": "CREATE INDEX idx_professionals_stripe_customer ON core.professionals USING btree (stripe_customer_id) WHERE (stripe_customer_id IS NOT NULL)"
                },
                {
                  "index_name": "professionals_auth_user_id_unique",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "auth_user_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX professionals_auth_user_id_unique ON core.professionals USING btree (auth_user_id) WHERE (deleted_at IS NULL)"
                },
                {
                  "index_name": "professionals_deleted_at_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "deleted_at"
                  ],
                  "index_definition": "CREATE INDEX professionals_deleted_at_idx ON core.professionals USING btree (deleted_at) WHERE (deleted_at IS NULL)"
                },
                {
                  "index_name": "professionals_email_search_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [],
                  "index_definition": "CREATE INDEX professionals_email_search_idx ON core.professionals USING btree (lower(primary_email))"
                },
                {
                  "index_name": "professionals_email_unique",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "primary_email"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX professionals_email_unique ON core.professionals USING btree (primary_email) WHERE (deleted_at IS NULL)"
                },
                {
                  "index_name": "professionals_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX professionals_pkey ON core.professionals USING btree (id)"
                },
                {
                  "index_name": "professionals_primary_enterprise_id_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "primary_enterprise_id"
                  ],
                  "index_definition": "CREATE INDEX professionals_primary_enterprise_id_idx ON core.professionals USING btree (primary_enterprise_id)"
                },
                {
                  "index_name": "professionals_public_contact_email_unique",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "public_contact_email"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX professionals_public_contact_email_unique ON core.professionals USING btree (public_contact_email) WHERE (public_contact_email IS NOT NULL)"
                },
                {
                  "index_name": "professionals_public_contact_number_unique",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "public_contact_number"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX professionals_public_contact_number_unique ON core.professionals USING btree (public_contact_number) WHERE (public_contact_number IS NOT NULL)"
                },
                {
                  "index_name": "professionals_qr_slug_unique",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "qr_slug"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX professionals_qr_slug_unique ON core.professionals USING btree (qr_slug) WHERE ((deleted_at IS NULL) AND (qr_slug IS NOT NULL))"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": [
                {
                  "policy_name": "professionals_all_authenticated",
                  "command": "ALL",
                  "is_permissive": true,
                  "using_expression": "((auth_user_id = ( SELECT auth.uid() AS uid)) OR (EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = ( SELECT auth.uid() AS uid)))))",
                  "with_check_expression": "((auth_user_id = ( SELECT auth.uid() AS uid)) OR (EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = ( SELECT auth.uid() AS uid)))))"
                }
              ]
            },
            {
              "table_name": "public_site_payload",
              "table_type": "VIEW",
              "table_comment": "Complete public site payload with two-flag section visibility (is_enabled + is_active)",
              "columns": [
                {
                  "column_name": "site_id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "subdomain",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "payload",
                  "ordinal_position": 4,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": null,
              "foreign_keys": null,
              "unique_constraints": null,
              "indexes": null,
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "service_categories",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "title",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "sort_order",
                  "ordinal_position": 4,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 5,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 6,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "deleted_at",
                  "ordinal_position": 7,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "service_categories_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": [
                {
                  "uq_name": "service_categories_id_professional_unique",
                  "columns": [
                    "id",
                    "professional_id"
                  ]
                }
              ],
              "indexes": [
                {
                  "index_name": "service_categories_id_professional_unique",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "id",
                    "professional_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX service_categories_id_professional_unique ON core.service_categories USING btree (id, professional_id)"
                },
                {
                  "index_name": "service_categories_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX service_categories_pkey ON core.service_categories USING btree (id)"
                },
                {
                  "index_name": "service_categories_professional_sort_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "sort_order"
                  ],
                  "index_definition": "CREATE INDEX service_categories_professional_sort_idx ON core.service_categories USING btree (professional_id, sort_order)"
                },
                {
                  "index_name": "service_categories_unique_title_per_professional",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX service_categories_unique_title_per_professional ON core.service_categories USING btree (professional_id, lower(title)) WHERE (deleted_at IS NULL)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "services",
              "table_type": "BASE TABLE",
              "table_comment": "Services offered by professionals with pricing and duration",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "title",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "description",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "category",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "price_cents",
                  "ordinal_position": 6,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 7,
                  "data_type": "character",
                  "udt_name": "bpchar",
                  "character_maximum_length": 3,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'AUD'::bpchar",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "duration_minutes",
                  "ordinal_position": 8,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "is_active",
                  "ordinal_position": 9,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "true",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "sort_order",
                  "ordinal_position": 10,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 11,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 12,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "deleted_at",
                  "ordinal_position": 13,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Soft delete timestamp (NULL = active)"
                },
                {
                  "column_name": "category_id",
                  "ordinal_position": 14,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "square_catalog_object_id",
                  "ordinal_position": 15,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 255,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "square_variation_id",
                  "ordinal_position": 16,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 255,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "square_catalog_version",
                  "ordinal_position": 17,
                  "data_type": "bigint",
                  "udt_name": "int8",
                  "character_maximum_length": null,
                  "numeric_precision": 64,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "square_last_synced_at",
                  "ordinal_position": 18,
                  "data_type": "timestamp without time zone",
                  "udt_name": "timestamp",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "square_sync_error",
                  "ordinal_position": 19,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "fresha_service_id",
                  "ordinal_position": 20,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 255,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Fresha service ID"
                },
                {
                  "column_name": "fresha_variation_id",
                  "ordinal_position": 21,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 255,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Fresha variation ID for this service"
                },
                {
                  "column_name": "fresha_service_version",
                  "ordinal_position": 22,
                  "data_type": "bigint",
                  "udt_name": "int8",
                  "character_maximum_length": null,
                  "numeric_precision": 64,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Version from Fresha service"
                },
                {
                  "column_name": "fresha_last_synced_at",
                  "ordinal_position": 23,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "When this service was last synced to/from Fresha"
                },
                {
                  "column_name": "fresha_sync_error",
                  "ordinal_position": 24,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Last error encountered during Fresha sync"
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "services_professional_fk",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "services_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": [
                {
                  "uq_name": "services_professional_fresha_variation_uq",
                  "columns": [
                    "fresha_variation_id",
                    "professional_id"
                  ]
                },
                {
                  "uq_name": "services_professional_square_variation_uq",
                  "columns": [
                    "professional_id",
                    "square_variation_id"
                  ]
                }
              ],
              "indexes": [
                {
                  "index_name": "services_active_order_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "sort_order"
                  ],
                  "index_definition": "CREATE INDEX services_active_order_idx ON core.services USING btree (professional_id, sort_order) WHERE (deleted_at IS NULL)"
                },
                {
                  "index_name": "services_fresha_service_id_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "fresha_service_id"
                  ],
                  "index_definition": "CREATE INDEX services_fresha_service_id_idx ON core.services USING btree (fresha_service_id)"
                },
                {
                  "index_name": "services_fresha_variation_id_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "fresha_variation_id"
                  ],
                  "index_definition": "CREATE INDEX services_fresha_variation_id_idx ON core.services USING btree (fresha_variation_id)"
                },
                {
                  "index_name": "services_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX services_pkey ON core.services USING btree (id)"
                },
                {
                  "index_name": "services_pro_active_sort_covering_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "title",
                    "price_cents",
                    "is_active",
                    "sort_order"
                  ],
                  "index_definition": "CREATE INDEX services_pro_active_sort_covering_idx ON core.services USING btree (professional_id, sort_order) INCLUDE (title, price_cents, is_active) WHERE ((deleted_at IS NULL) AND (is_active = true))"
                },
                {
                  "index_name": "services_prof_sort_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "sort_order",
                    "created_at"
                  ],
                  "index_definition": "CREATE INDEX services_prof_sort_idx ON core.services USING btree (professional_id, sort_order, created_at)"
                },
                {
                  "index_name": "services_professional_category_sort_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "sort_order",
                    "category_id"
                  ],
                  "index_definition": "CREATE INDEX services_professional_category_sort_idx ON core.services USING btree (professional_id, category_id, sort_order)"
                },
                {
                  "index_name": "services_professional_fresha_variation_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "fresha_variation_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX services_professional_fresha_variation_uq ON core.services USING btree (professional_id, fresha_variation_id)"
                },
                {
                  "index_name": "services_professional_id_deleted_at_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "deleted_at"
                  ],
                  "index_definition": "CREATE INDEX services_professional_id_deleted_at_idx ON core.services USING btree (professional_id, deleted_at)"
                },
                {
                  "index_name": "services_professional_sort_order_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "sort_order"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX services_professional_sort_order_uq ON core.services USING btree (professional_id, sort_order) WHERE (deleted_at IS NULL)"
                },
                {
                  "index_name": "services_professional_square_variation_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "square_variation_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX services_professional_square_variation_uq ON core.services USING btree (professional_id, square_variation_id)"
                },
                {
                  "index_name": "services_square_catalog_object_id_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "square_catalog_object_id"
                  ],
                  "index_definition": "CREATE INDEX services_square_catalog_object_id_idx ON core.services USING btree (square_catalog_object_id)"
                },
                {
                  "index_name": "services_square_variation_id_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "square_variation_id"
                  ],
                  "index_definition": "CREATE INDEX services_square_variation_id_idx ON core.services USING btree (square_variation_id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": [
                {
                  "policy_name": "services_pro_all",
                  "command": "ALL",
                  "is_permissive": true,
                  "using_expression": "(professional_id = ( SELECT professionals.id\n   FROM core.professionals\n  WHERE ((professionals.auth_user_id = auth.uid()) AND (professionals.deleted_at IS NULL))))",
                  "with_check_expression": "(professional_id = ( SELECT professionals.id\n   FROM core.professionals\n  WHERE ((professionals.auth_user_id = auth.uid()) AND (professionals.deleted_at IS NULL))))"
                },
                {
                  "policy_name": "services_staff_all",
                  "command": "ALL",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff\n  WHERE (comet_staff.auth_user_id = auth.uid())))",
                  "with_check_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff\n  WHERE (comet_staff.auth_user_id = auth.uid())))"
                }
              ]
            },
            {
              "table_name": "site_media",
              "table_type": "BASE TABLE",
              "table_comment": "Gallery images for sites (max 6 per site, enforced by trigger)",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "site_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "path",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "alt_text",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "sort_order",
                  "ordinal_position": 6,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 7,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 8,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "deleted_at",
                  "ordinal_position": 9,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "is_active",
                  "ordinal_position": 10,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "true",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "pool",
                  "ordinal_position": 11,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 20,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'gallery'::character varying",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Image pool: gallery or content"
                },
                {
                  "column_name": "media_type",
                  "ordinal_position": 12,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 10,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'image'::character varying",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Media type: image or video"
                },
                {
                  "column_name": "processing_state",
                  "ordinal_position": 13,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 20,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'pending'::character varying",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Processing lifecycle: pending | processing | ready | failed"
                },
                {
                  "column_name": "original_mime",
                  "ordinal_position": 14,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 100,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "MIME type of the uploaded original file"
                },
                {
                  "column_name": "original_size_bytes",
                  "ordinal_position": 15,
                  "data_type": "bigint",
                  "udt_name": "int8",
                  "character_maximum_length": null,
                  "numeric_precision": 64,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "File size of the uploaded original in bytes"
                },
                {
                  "column_name": "duration_ms",
                  "ordinal_position": 16,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Video duration in milliseconds (null for images)"
                },
                {
                  "column_name": "poster_path",
                  "ordinal_position": 17,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Storage path to the video poster image (null for images)"
                },
                {
                  "column_name": "processing_error",
                  "ordinal_position": 18,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Error message when processing_state = failed"
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "site_images_site_fk",
                  "column_name": "site_id",
                  "foreign_schema": "core",
                  "foreign_table": "sites",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "site_images_site_id_fkey",
                  "column_name": "site_id",
                  "foreign_schema": "core",
                  "foreign_table": "sites",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "si_pool_active",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "site_id",
                    "is_active",
                    "pool"
                  ],
                  "index_definition": "CREATE INDEX si_pool_active ON core.site_media USING btree (site_id, pool, is_active)"
                },
                {
                  "index_name": "si_pool_media_active",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "site_id",
                    "sort_order",
                    "pool",
                    "media_type"
                  ],
                  "index_definition": "CREATE INDEX si_pool_media_active ON core.site_media USING btree (site_id, pool, media_type, sort_order) WHERE ((deleted_at IS NULL) AND (is_active = true))"
                },
                {
                  "index_name": "site_images_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX site_images_pkey ON core.site_media USING btree (id)"
                },
                {
                  "index_name": "site_images_site_active_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "site_id"
                  ],
                  "index_definition": "CREATE INDEX site_images_site_active_idx ON core.site_media USING btree (site_id) WHERE (deleted_at IS NULL)"
                },
                {
                  "index_name": "site_images_site_sort_active_unique",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "site_id",
                    "sort_order"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX site_images_site_sort_active_unique ON core.site_media USING btree (site_id, sort_order) WHERE (deleted_at IS NULL)"
                },
                {
                  "index_name": "site_images_site_sort_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "site_id",
                    "sort_order"
                  ],
                  "index_definition": "CREATE INDEX site_images_site_sort_idx ON core.site_media USING btree (site_id, sort_order)"
                },
                {
                  "index_name": "site_images_site_sort_order_active_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "site_id",
                    "sort_order"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX site_images_site_sort_order_active_uq ON core.site_media USING btree (site_id, sort_order) WHERE ((deleted_at IS NULL) AND (is_active = true))"
                },
                {
                  "index_name": "site_media_site_active_sort_covering_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "site_id",
                    "alt_text",
                    "sort_order",
                    "pool",
                    "media_type"
                  ],
                  "index_definition": "CREATE INDEX site_media_site_active_sort_covering_idx ON core.site_media USING btree (site_id, sort_order) INCLUDE (alt_text, media_type, pool) WHERE ((deleted_at IS NULL) AND (is_active = true))"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": [
                {
                  "policy_name": "site_images_delete_staff",
                  "command": "DELETE",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = ( SELECT auth.uid() AS uid))))",
                  "with_check_expression": null
                },
                {
                  "policy_name": "site_images_insert_authenticated",
                  "command": "INSERT",
                  "is_permissive": true,
                  "using_expression": null,
                  "with_check_expression": "((EXISTS ( SELECT 1\n   FROM (core.sites s\n     JOIN core.professionals p ON ((p.id = s.professional_id)))\n  WHERE ((s.id = site_media.site_id) AND (p.auth_user_id = auth.uid()) AND (p.deleted_at IS NULL)))) OR (EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid()))))"
                },
                {
                  "policy_name": "site_images_public_read_published",
                  "command": "SELECT",
                  "is_permissive": true,
                  "using_expression": "((deleted_at IS NULL) AND (EXISTS ( SELECT 1\n   FROM core.sites s\n  WHERE ((s.id = site_media.site_id) AND (s.is_published = true)))))",
                  "with_check_expression": null
                },
                {
                  "policy_name": "site_images_select_authenticated",
                  "command": "SELECT",
                  "is_permissive": true,
                  "using_expression": "((EXISTS ( SELECT 1\n   FROM (core.sites s\n     JOIN core.professionals p ON ((p.id = s.professional_id)))\n  WHERE ((s.id = site_media.site_id) AND (p.auth_user_id = auth.uid()) AND (p.deleted_at IS NULL)))) OR (EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid()))))",
                  "with_check_expression": null
                },
                {
                  "policy_name": "site_images_update_authenticated",
                  "command": "UPDATE",
                  "is_permissive": true,
                  "using_expression": "((EXISTS ( SELECT 1\n   FROM (core.sites s\n     JOIN core.professionals p ON ((p.id = s.professional_id)))\n  WHERE ((s.id = site_media.site_id) AND (p.auth_user_id = auth.uid()) AND (p.deleted_at IS NULL)))) OR (EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid()))))",
                  "with_check_expression": "((EXISTS ( SELECT 1\n   FROM (core.sites s\n     JOIN core.professionals p ON ((p.id = s.professional_id)))\n  WHERE ((s.id = site_media.site_id) AND (p.auth_user_id = auth.uid()) AND (p.deleted_at IS NULL)))) OR (EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid()))))"
                }
              ]
            },
            {
              "table_name": "site_subdomain_aliases",
              "table_type": "BASE TABLE",
              "table_comment": "Alternative subdomains that redirect to sites",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "site_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "subdomain",
                  "ordinal_position": 3,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 63,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 4,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 5,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "site_subdomain_aliases_site_fk",
                  "column_name": "site_id",
                  "foreign_schema": "core",
                  "foreign_table": "sites",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "site_subdomain_aliases_site_id_fkey",
                  "column_name": "site_id",
                  "foreign_schema": "core",
                  "foreign_table": "sites",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "core_site_subdomain_aliases_site_id_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "site_id"
                  ],
                  "index_definition": "CREATE INDEX core_site_subdomain_aliases_site_id_idx ON core.site_subdomain_aliases USING btree (site_id)"
                },
                {
                  "index_name": "core_site_subdomain_aliases_subdomain_lower_unique",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [],
                  "index_definition": "CREATE UNIQUE INDEX core_site_subdomain_aliases_subdomain_lower_unique ON core.site_subdomain_aliases USING btree (lower((subdomain)::text))"
                },
                {
                  "index_name": "site_subdomain_aliases_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX site_subdomain_aliases_pkey ON core.site_subdomain_aliases USING btree (id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": [
                {
                  "policy_name": "aliases_pro_all",
                  "command": "ALL",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM (core.sites s\n     JOIN core.professionals p ON ((p.id = s.professional_id)))\n  WHERE ((s.id = site_subdomain_aliases.site_id) AND (p.auth_user_id = auth.uid()))))",
                  "with_check_expression": "(EXISTS ( SELECT 1\n   FROM (core.sites s\n     JOIN core.professionals p ON ((p.id = s.professional_id)))\n  WHERE ((s.id = site_subdomain_aliases.site_id) AND (p.auth_user_id = auth.uid()))))"
                },
                {
                  "policy_name": "aliases_public_read",
                  "command": "SELECT",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.sites s\n  WHERE ((s.id = site_subdomain_aliases.site_id) AND (s.is_published = true))))",
                  "with_check_expression": null
                },
                {
                  "policy_name": "aliases_staff_all",
                  "command": "ALL",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff\n  WHERE (comet_staff.auth_user_id = auth.uid())))",
                  "with_check_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff\n  WHERE (comet_staff.auth_user_id = auth.uid())))"
                }
              ]
            },
            {
              "table_name": "sites",
              "table_type": "BASE TABLE",
              "table_comment": "Professional websites with subdomains (1:1 with professionals)",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "subdomain",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "theme_id",
                  "ordinal_position": 4,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "is_published",
                  "ordinal_position": 5,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "false",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Whether site is publicly visible"
                },
                {
                  "column_name": "settings",
                  "ordinal_position": 6,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 7,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 8,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "subdomain_changed_at",
                  "ordinal_position": 9,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "sites_professional_fk",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "sites_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "sites_theme_fk",
                  "column_name": "theme_id",
                  "foreign_schema": "core",
                  "foreign_table": "themes",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                },
                {
                  "fk_name": "sites_theme_id_fkey",
                  "column_name": "theme_id",
                  "foreign_schema": "core",
                  "foreign_table": "themes",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "NO ACTION"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "core_sites_subdomain_lower_unique",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [],
                  "index_definition": "CREATE UNIQUE INDEX core_sites_subdomain_lower_unique ON core.sites USING btree (lower(subdomain))"
                },
                {
                  "index_name": "sites_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX sites_pkey ON core.sites USING btree (id)"
                },
                {
                  "index_name": "sites_professional_unique",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX sites_professional_unique ON core.sites USING btree (professional_id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": [
                {
                  "policy_name": "sites_delete_authenticated",
                  "command": "DELETE",
                  "is_permissive": true,
                  "using_expression": "((EXISTS ( SELECT 1\n   FROM core.professionals p\n  WHERE ((p.id = sites.professional_id) AND (p.auth_user_id = auth.uid()) AND (p.deleted_at IS NULL)))) OR (EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid()))))",
                  "with_check_expression": null
                },
                {
                  "policy_name": "sites_insert_authenticated",
                  "command": "INSERT",
                  "is_permissive": true,
                  "using_expression": null,
                  "with_check_expression": "((EXISTS ( SELECT 1\n   FROM core.professionals p\n  WHERE ((p.id = sites.professional_id) AND (p.auth_user_id = auth.uid()) AND (p.deleted_at IS NULL)))) OR (EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid()))))"
                },
                {
                  "policy_name": "sites_public_read_published",
                  "command": "SELECT",
                  "is_permissive": true,
                  "using_expression": "(is_published = true)",
                  "with_check_expression": null
                },
                {
                  "policy_name": "sites_select_authenticated",
                  "command": "SELECT",
                  "is_permissive": true,
                  "using_expression": "((is_published = true) OR (EXISTS ( SELECT 1\n   FROM core.professionals p\n  WHERE ((p.id = sites.professional_id) AND (p.auth_user_id = auth.uid()) AND (p.deleted_at IS NULL)))) OR (EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid()))))",
                  "with_check_expression": null
                },
                {
                  "policy_name": "sites_update_authenticated",
                  "command": "UPDATE",
                  "is_permissive": true,
                  "using_expression": "((EXISTS ( SELECT 1\n   FROM core.professionals p\n  WHERE ((p.id = sites.professional_id) AND (p.auth_user_id = auth.uid()) AND (p.deleted_at IS NULL)))) OR (EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid()))))",
                  "with_check_expression": "((EXISTS ( SELECT 1\n   FROM core.professionals p\n  WHERE ((p.id = sites.professional_id) AND (p.auth_user_id = auth.uid()) AND (p.deleted_at IS NULL)))) OR (EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = auth.uid()))))"
                }
              ]
            },
            {
              "table_name": "themes",
              "table_type": "BASE TABLE",
              "table_comment": "Site themes with configuration (only 1 can be default)",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "key",
                  "ordinal_position": 2,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "name",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "description",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "config",
                  "ordinal_position": 5,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "is_default",
                  "ordinal_position": 6,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "false",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 7,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 8,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": null,
              "unique_constraints": [
                {
                  "uq_name": "themes_key_key",
                  "columns": [
                    "key"
                  ]
                }
              ],
              "indexes": [
                {
                  "index_name": "themes_key_key",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "key"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX themes_key_key ON core.themes USING btree (key)"
                },
                {
                  "index_name": "themes_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX themes_pkey ON core.themes USING btree (id)"
                },
                {
                  "index_name": "themes_single_default",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "is_default"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX themes_single_default ON core.themes USING btree (is_default) WHERE (is_default = true)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": [
                {
                  "policy_name": "themes_delete_staff",
                  "command": "DELETE",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = ( SELECT auth.uid() AS uid))))",
                  "with_check_expression": null
                },
                {
                  "policy_name": "themes_insert_staff",
                  "command": "INSERT",
                  "is_permissive": true,
                  "using_expression": null,
                  "with_check_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = ( SELECT auth.uid() AS uid))))"
                },
                {
                  "policy_name": "themes_public_read",
                  "command": "SELECT",
                  "is_permissive": true,
                  "using_expression": "true",
                  "with_check_expression": null
                },
                {
                  "policy_name": "themes_select_authenticated",
                  "command": "SELECT",
                  "is_permissive": true,
                  "using_expression": "true",
                  "with_check_expression": null
                },
                {
                  "policy_name": "themes_update_staff",
                  "command": "UPDATE",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = ( SELECT auth.uid() AS uid))))",
                  "with_check_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff cs\n  WHERE (cs.auth_user_id = ( SELECT auth.uid() AS uid))))"
                }
              ]
            },
            {
              "table_name": "waitlist_signups",
              "table_type": "BASE TABLE",
              "table_comment": "Pre-launch waitlist submissions. One canonical row per normalized email.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "name",
                  "ordinal_position": 2,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "email",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "email_lc",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "phone",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "applicant_type",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "applicant_type_other",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "industry",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "industry_other",
                  "ordinal_position": 9,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "pilot_program_opt_in",
                  "ordinal_position": 10,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "false",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Whether the applicant opted in for pilot-program consideration."
                },
                {
                  "column_name": "number_of_team_members",
                  "ordinal_position": 11,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "number_of_affiliates_ambassadors",
                  "ordinal_position": 12,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "is_brand_partner_or_ambassador",
                  "ordinal_position": 13,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currently_sells_products",
                  "ordinal_position": 14,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "consent_source",
                  "ordinal_position": 15,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'waitlist_form'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "consent_ip_hash",
                  "ordinal_position": 16,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "consent_user_agent",
                  "ordinal_position": 17,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "last_submitted_at",
                  "ordinal_position": 18,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Latest submission timestamp for this email (updated on re-submission)."
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 19,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 20,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": null,
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "waitlist_signups_email_lc_unique",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "email_lc"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX waitlist_signups_email_lc_unique ON core.waitlist_signups USING btree (email_lc)"
                },
                {
                  "index_name": "waitlist_signups_industry_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "industry"
                  ],
                  "index_definition": "CREATE INDEX waitlist_signups_industry_idx ON core.waitlist_signups USING btree (industry)"
                },
                {
                  "index_name": "waitlist_signups_last_submitted_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "last_submitted_at"
                  ],
                  "index_definition": "CREATE INDEX waitlist_signups_last_submitted_idx ON core.waitlist_signups USING btree (last_submitted_at DESC)"
                },
                {
                  "index_name": "waitlist_signups_pilot_opt_in_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "pilot_program_opt_in"
                  ],
                  "index_definition": "CREATE INDEX waitlist_signups_pilot_opt_in_idx ON core.waitlist_signups USING btree (pilot_program_opt_in)"
                },
                {
                  "index_name": "waitlist_signups_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX waitlist_signups_pkey ON core.waitlist_signups USING btree (id)"
                },
                {
                  "index_name": "waitlist_signups_type_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "applicant_type"
                  ],
                  "index_definition": "CREATE INDEX waitlist_signups_type_idx ON core.waitlist_signups USING btree (applicant_type)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            }
          ],
          "enums": null,
          "functions": [
            {
              "function_name": "comet_schema_report",
              "arguments": "include_schemas text[] DEFAULT NULL::text[], exclude_schemas text[] DEFAULT ARRAY['auth'::text, 'extensions'::text, 'graphql'::text, 'graphql_public'::text, 'pgbouncer'::text, 'realtime'::text, 'vault'::text, 'information_schema'::text, 'pg_catalog'::text]",
              "return_type": "jsonb",
              "body": "\ndeclare\n  base jsonb;\n  storage_buckets jsonb := '[]'::jsonb;\n\n  include_storage boolean;\n\n  -- buckets columns may vary across storage versions, so detect safely\n  has_id boolean;\n  has_name boolean;\n  has_public boolean;\n  has_file_size_limit boolean;\n  has_allowed_mime_types boolean;\n  has_owner_id boolean;\n  has_owner boolean;\n  has_created_at boolean;\n  has_updated_at boolean;\n  has_type boolean;\n\n  has_objects boolean;\n\n  bucket_expr text;\n  join_clause text;\n  sql text;\n  order_expr text;\nbegin\n  -- ===== Base schema report =====\n  with included_schemas as (\n    select n.nspname\n    from pg_namespace n\n    where\n      (\n        include_schemas is null\n        and n.nspname !~ '^pg_'\n        and not (n.nspname = any(exclude_schemas))\n      )\n      or (\n        include_schemas is not null\n        and n.nspname = any(include_schemas)\n      )\n  ),\n  rels as (\n    select\n      n.nspname as schema,\n      c.relname as name,\n      c.oid as oid,\n      c.relkind as relkind,\n      case c.relkind\n        when 'r' then 'table'\n        when 'p' then 'partitioned_table'\n        when 'v' then 'view'\n        when 'm' then 'materialized_view'\n        when 'f' then 'foreign_table'\n        else c.relkind::text\n      end as type,\n      c.relrowsecurity as rls_enabled,\n      c.relforcerowsecurity as rls_forced,\n      pg_total_relation_size(c.oid) as total_bytes,\n      pg_relation_size(c.oid) as heap_bytes,\n      pg_indexes_size(c.oid) as index_bytes,\n      coalesce(st.n_live_tup, null) as live_rows_est\n    from pg_class c\n    join pg_namespace n on n.oid = c.relnamespace\n    join included_schemas s on s.nspname = n.nspname\n    left join pg_stat_user_tables st on st.relid = c.oid\n    where c.relkind in ('r','p','v','m','f')\n  ),\n  report_tables as (\n    select jsonb_agg(\n      jsonb_build_object(\n        'schema', r.schema,\n        'name', r.name,\n        'type', r.type,\n        'size_bytes', jsonb_build_object(\n          'total', r.total_bytes,\n          'heap', r.heap_bytes,\n          'indexes', r.index_bytes\n        ),\n        'live_rows_est', r.live_rows_est,\n        'rls', jsonb_build_object(\n          'enabled', r.rls_enabled,\n          'forced', r.rls_forced,\n          'policies', (\n            select coalesce(jsonb_agg(pol order by pol->>'name'), '[]'::jsonb)\n            from (\n              select jsonb_build_object(\n                'name', p.polname,\n                'command', case p.polcmd\n                  when 'r' then 'SELECT'\n                  when 'a' then 'INSERT'\n                  when 'w' then 'UPDATE'\n                  when 'd' then 'DELETE'\n                  else p.polcmd::text\n                end,\n                'roles', (\n                  case\n                    when p.polroles = '{0}'::oid[] then '[\"PUBLIC\"]'::jsonb\n                    when p.polroles is null or array_length(p.polroles, 1) is null then '[\"PUBLIC\"]'::jsonb\n                    else (\n                      select jsonb_agg(pr.rolname order by pr.rolname)\n                      from unnest(p.polroles) roid\n                      join pg_roles pr on pr.oid = roid\n                    )\n                  end\n                ),\n                'using', pg_get_expr(p.polqual, p.polrelid),\n                'with_check', pg_get_expr(p.polwithcheck, p.polrelid)\n              ) as pol\n              from pg_policy p\n              where p.polrelid = r.oid\n            ) x\n          )\n        ),\n        'columns', (\n          select coalesce(jsonb_agg(\n            jsonb_build_object(\n              'name', a.attname,\n              'type', format_type(a.atttypid, a.atttypmod),\n              'not_null', a.attnotnull,\n              'default', pg_get_expr(ad.adbin, ad.adrelid),\n              'identity', nullif(a.attidentity, ''),\n              'generated', nullif(a.attgenerated, '')\n            )\n            order by a.attnum\n          ), '[]'::jsonb)\n          from pg_attribute a\n          left join pg_attrdef ad\n            on ad.adrelid = a.attrelid and ad.adnum = a.attnum\n          where a.attrelid = r.oid and a.attnum > 0 and not a.attisdropped\n        ),\n        'grants', (\n          select coalesce(jsonb_object_agg(grantee, privileges), '{}'::jsonb)\n          from (\n            select\n              tp.grantee,\n              jsonb_agg(distinct tp.privilege_type order by tp.privilege_type) as privileges\n            from information_schema.table_privileges tp\n            where tp.table_schema = r.schema and tp.table_name = r.name\n            group by tp.grantee\n          ) g\n        ),\n        'indexes', (\n          select coalesce(jsonb_agg(\n            jsonb_build_object(\n              'name', i.relname,\n              'is_unique', ix.indisunique,\n              'is_primary', ix.indisprimary,\n              'definition', pg_get_indexdef(i.oid)\n            )\n            order by i.relname\n          ), '[]'::jsonb)\n          from pg_index ix\n          join pg_class i on i.oid = ix.indexrelid\n          where ix.indrelid = r.oid\n        ),\n        'constraints', (\n          select coalesce(jsonb_agg(\n            jsonb_build_object(\n              'name', c.conname,\n              'type', c.contype,                          -- p=PK, u=UK, f=FK, c=CHECK, x=EXCLUDE\n              'definition', pg_get_constraintdef(c.oid, true),\n              'is_deferrable', c.condeferrable,\n              'is_deferred', c.condeferred,\n              'fk_refs', case when c.contype = 'f' then\n                jsonb_build_object(\n                  'target_table', format('%I.%I', nf.nspname, rf.relname),\n                  'target_columns', (\n                    select jsonb_agg(attname order by ordinality)\n                    from unnest(c.confkey) with ordinality k\n                    join pg_attribute a on a.attrelid = c.confrelid and a.attnum = k\n                  )\n                ) else null end\n            )\n            order by c.conname\n          ), '[]'::jsonb)\n          from pg_constraint c\n          join pg_class rc on rc.oid = c.conrelid\n          join pg_namespace rn on rn.oid = rc.relnamespace\n          left join pg_class rf on rf.oid = c.confrelid\n          left join pg_namespace nf on nf.oid = rf.relnamespace\n          where c.conrelid = r.oid\n        ),\n        'triggers', (\n          select coalesce(jsonb_agg(\n            jsonb_build_object(\n              'name', t.tgname,\n              'enabled', t.tgenabled,\n              'definition', pg_get_triggerdef(t.oid, true)\n            )\n            order by t.tgname\n          ), '[]'::jsonb)\n          from pg_trigger t\n          where t.tgrelid = r.oid and not t.tgisinternal\n        )\n      )\n      order by r.schema, r.name\n    ) as tables\n    from rels r\n  ),\n  report_functions as (\n    select coalesce(jsonb_agg(\n      jsonb_build_object(\n        'schema', n.nspname,\n        'name', p.proname,\n        'identity_args', pg_get_function_identity_arguments(p.oid),\n        'language', l.lanname,\n        'security_definer', p.prosecdef,\n        'search_path_set', exists (\n          select 1\n          from unnest(coalesce(p.proconfig, array[]::text[])) cfg\n          where cfg like 'search_path=%'\n        ),\n        'definition', pg_get_functiondef(p.oid)\n      )\n      order by n.nspname, p.proname\n    ), '[]'::jsonb) as functions\n    from pg_proc p\n    join pg_namespace n on n.oid = p.pronamespace\n    join pg_language l on l.oid = p.prolang\n    join included_schemas s on s.nspname = n.nspname\n  )\n  select jsonb_build_object(\n    'generated_at', now(),\n    'schemas', (select jsonb_agg(nspname order by nspname) from included_schemas),\n    'tables_and_views', (select tables from report_tables),\n    'functions', (select functions from report_functions)\n  )\n  into base;\n\n  -- ===== Storage buckets (rows in storage.buckets) =====\n  include_storage := (include_schemas is null) or ('storage' = any(include_schemas));\n\n  if include_storage and to_regclass('storage.buckets') is not null then\n    -- detect bucket table columns\n    select exists (select 1 from information_schema.columns where table_schema='storage' and table_name='buckets' and column_name='id') into has_id;\n    select exists (select 1 from information_schema.columns where table_schema='storage' and table_name='buckets' and column_name='name') into has_name;\n    select exists (select 1 from information_schema.columns where table_schema='storage' and table_name='buckets' and column_name='public') into has_public;\n    select exists (select 1 from information_schema.columns where table_schema='storage' and table_name='buckets' and column_name='file_size_limit') into has_file_size_limit;\n    select exists (select 1 from information_schema.columns where table_schema='storage' and table_name='buckets' and column_name='allowed_mime_types') into has_allowed_mime_types;\n    select exists (select 1 from information_schema.columns where table_schema='storage' and table_name='buckets' and column_name='owner_id') into has_owner_id;\n    select exists (select 1 from information_schema.columns where table_schema='storage' and table_name='buckets' and column_name='owner') into has_owner;\n    select exists (select 1 from information_schema.columns where table_schema='storage' and table_name='buckets' and column_name='created_at') into has_created_at;\n    select exists (select 1 from information_schema.columns where table_schema='storage' and table_name='buckets' and column_name='updated_at') into has_updated_at;\n    select exists (select 1 from information_schema.columns where table_schema='storage' and table_name='buckets' and column_name='type') into has_type;\n\n    has_objects := to_regclass('storage.objects') is not null;\n\n    -- choose a stable ordering\n    order_expr := case\n      when has_id then 'b.id'\n      when has_name then 'b.name'\n      else '1'\n    end;\n\n    -- build jsonb object expression dynamically (so missing cols won't break the function)\n    bucket_expr := 'jsonb_build_object(';\n\n    if has_id then\n      bucket_expr := bucket_expr || quote_literal('id') || ', b.id';\n    else\n      bucket_expr := bucket_expr || quote_literal('id') || ', null';\n    end if;\n\n    if has_name then\n      bucket_expr := bucket_expr || ', ' || quote_literal('name') || ', b.name';\n    end if;\n\n    if has_public then\n      bucket_expr := bucket_expr || ', ' || quote_literal('public') || ', b.public';\n    end if;\n\n    if has_type then\n      bucket_expr := bucket_expr || ', ' || quote_literal('type') || ', b.type';\n    end if;\n\n    if has_file_size_limit then\n      bucket_expr := bucket_expr || ', ' || quote_literal('file_size_limit') || ', b.file_size_limit';\n    end if;\n\n    if has_allowed_mime_types then\n      bucket_expr := bucket_expr || ', ' || quote_literal('allowed_mime_types') || ', b.allowed_mime_types';\n    end if;\n\n    if has_owner_id then\n      bucket_expr := bucket_expr || ', ' || quote_literal('owner_id') || ', b.owner_id';\n    elsif has_owner then\n      bucket_expr := bucket_expr || ', ' || quote_literal('owner_id') || ', b.owner';\n    end if;\n\n    if has_created_at then\n      bucket_expr := bucket_expr || ', ' || quote_literal('created_at') || ', b.created_at';\n    end if;\n\n    if has_updated_at then\n      bucket_expr := bucket_expr || ', ' || quote_literal('updated_at') || ', b.updated_at';\n    end if;\n\n    if has_objects then\n      join_clause := $j$\n        left join lateral (\n          select\n            count(*)::bigint as object_count,\n            sum(\n              case\n                when (obj.metadata ? 'size')\n                 and (obj.metadata->>'size') ~ '^[0-9]+$'\n                then (obj.metadata->>'size')::bigint\n                else null\n              end\n            ) as objects_bytes_est\n          from storage.objects obj\n          where obj.bucket_id = b.id\n        ) o on true\n      $j$;\n\n      bucket_expr := bucket_expr\n        || ', ' || quote_literal('object_count') || ', coalesce(o.object_count, 0)'\n        || ', ' || quote_literal('objects_bytes_est') || ', o.objects_bytes_est';\n    else\n      join_clause := '';\n      bucket_expr := bucket_expr\n        || ', ' || quote_literal('object_count') || ', 0'\n        || ', ' || quote_literal('objects_bytes_est') || ', null';\n    end if;\n\n    bucket_expr := bucket_expr || ')';\n\n    sql := format(\n      'select coalesce(jsonb_agg(%s order by %s), ''[]''::jsonb)\n       from storage.buckets b\n       %s',\n      bucket_expr,\n      order_expr,\n      join_clause\n    );\n\n    execute sql into storage_buckets;\n  end if;\n\n  return base || jsonb_build_object('storage_buckets', storage_buckets);\nend;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "prevent_staff_escalation",
              "arguments": "",
              "return_type": "trigger",
              "body": "\ndeclare\n  uid uuid := (select auth.uid());\n  is_admin boolean;\nbegin\n  -- service_role / non-jwt contexts often have null uid; allow those\n  if uid is null then\n    return new;\n  end if;\n\n  select exists (\n    select 1\n    from core.comet_staff cs\n    where cs.auth_user_id = uid\n      and cs.role = 'admin'\n  ) into is_admin;\n\n  if not is_admin then\n    if new.role is distinct from old.role then\n      raise exception 'Only admins can change staff role';\n    end if;\n\n    if new.auth_user_id is distinct from old.auth_user_id then\n      raise exception 'Only admins can change auth_user_id';\n    end if;\n  end if;\n\n  return new;\nend;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "set_brand_fonts_updated_at",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nBEGIN\n    NEW.updated_at = now();\n    RETURN NEW;\nEND;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "set_default_theme_for_site",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nbegin\n  if new.theme_id is null then\n    select id\n    into new.theme_id\n    from core.themes\n    order by is_default desc, created_at\n    limit 1;\n\n    if new.theme_id is null then\n      raise exception 'Cannot create site: no themes exist in core.themes';\n    end if;\n  end if;\n\n  return new;\nend;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "set_email_subscription_defaults",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nbegin\n  if new.email is not null then\n    new.email_lc := lower(new.email);\n  end if;\n  if new.unsubscribe_token is null then\n    new.unsubscribe_token := encode(gen_random_bytes(16), 'hex');\n  end if;\n  return new;\nend;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "set_media_variants_updated_at",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nBEGIN\n    NEW.updated_at = now();\n    RETURN NEW;\nEND;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "set_professional_confirmation_preferences_updated_at",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nBEGIN\n    NEW.updated_at = now();\n    RETURN NEW;\nEND;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "set_professional_defaults",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nbegin\n  if new.handle is not null then\n    new.handle_lc := lower(new.handle);\n  end if;\n  if new.qr_slug is null then\n    new.qr_slug := encode(gen_random_bytes(16), 'hex');\n  end if;\n  return new;\nend;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "set_professional_integrations_updated_at",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nBEGIN\n    NEW.updated_at = now();\n    RETURN NEW;\nEND;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "set_updated_at",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nBEGIN\n  NEW.updated_at = now();\n  RETURN NEW;\nEND;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "validate_brand_team_membership",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nDECLARE\n    brand_type text;\nBEGIN\n    SELECT p.professional_type\n      INTO brand_type\n      FROM core.professionals p\n     WHERE p.id = NEW.brand_professional_id\n       AND p.deleted_at IS NULL;\n\n    IF brand_type IS DISTINCT FROM 'brand' THEN\n        RAISE EXCEPTION 'brand_team_memberships.brand_professional_id must reference professional_type = brand'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    RETURN NEW;\nEND;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "validate_enterprise_brand_link",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nDECLARE\n    linked_brand_type text;\n    linked_enterprise_type text;\nBEGIN\n    SELECT p.professional_type\n      INTO linked_brand_type\n      FROM core.professionals p\n     WHERE p.id = NEW.brand_professional_id\n       AND p.deleted_at IS NULL;\n\n    IF linked_brand_type IS DISTINCT FROM 'brand' THEN\n        RAISE EXCEPTION 'enterprise_brand_links.brand_professional_id must reference professional_type = brand'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    SELECT e.enterprise_type\n      INTO linked_enterprise_type\n      FROM core.enterprises e\n     WHERE e.id = NEW.enterprise_id\n       AND e.deleted_at IS NULL;\n\n    IF linked_enterprise_type IS DISTINCT FROM 'distributor' THEN\n        RAISE EXCEPTION 'enterprise_brand_links.enterprise_id must reference enterprise_type = distributor'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    RETURN NEW;\nEND;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "validate_influencer_promoter_contract",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nDECLARE\n    influencer_type text;\n    promoter_type   text;\nBEGIN\n    SELECT p.professional_type\n      INTO influencer_type\n      FROM core.professionals p\n     WHERE p.id = NEW.influencer_professional_id\n       AND p.deleted_at IS NULL;\n\n    IF COALESCE(influencer_type, '') NOT IN ('ambassador', 'influencer') THEN\n        RAISE EXCEPTION 'influencer_professional_id must reference a professional_type = ambassador'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    SELECT e.enterprise_type\n      INTO promoter_type\n      FROM core.enterprises e\n     WHERE e.id = NEW.promoter_enterprise_id\n       AND e.deleted_at IS NULL;\n\n    IF promoter_type IS DISTINCT FROM 'promoter' THEN\n        RAISE EXCEPTION 'promoter_enterprise_id must reference an enterprise_type = promoter'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    RETURN NEW;\nEND;\n",
              "language": "plpgsql"
            }
          ]
        },
        {
          "schema_name": "public",
          "tables": [
            {
              "table_name": "failed_jobs",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "bigint",
                  "udt_name": "int8",
                  "character_maximum_length": null,
                  "numeric_precision": 64,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "nextval('failed_jobs_id_seq'::regclass)",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "uuid",
                  "ordinal_position": 2,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 255,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "connection",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "queue",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "payload",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "exception",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "failed_at",
                  "ordinal_position": 7,
                  "data_type": "timestamp without time zone",
                  "udt_name": "timestamp",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "CURRENT_TIMESTAMP",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": null,
              "unique_constraints": [
                {
                  "uq_name": "failed_jobs_uuid_unique",
                  "columns": [
                    "uuid"
                  ]
                }
              ],
              "indexes": [
                {
                  "index_name": "failed_jobs_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX failed_jobs_pkey ON public.failed_jobs USING btree (id)"
                },
                {
                  "index_name": "failed_jobs_uuid_unique",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "uuid"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX failed_jobs_uuid_unique ON public.failed_jobs USING btree (uuid)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "job_batches",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 255,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "name",
                  "ordinal_position": 2,
                  "data_type": "character varying",
                  "udt_name": "varchar",
                  "character_maximum_length": 255,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "total_jobs",
                  "ordinal_position": 3,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "pending_jobs",
                  "ordinal_position": 4,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "failed_jobs",
                  "ordinal_position": 5,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "failed_job_ids",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "options",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "cancelled_at",
                  "ordinal_position": 8,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 9,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "finished_at",
                  "ordinal_position": 10,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": null,
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "job_batches_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX job_batches_pkey ON public.job_batches USING btree (id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": null
            }
          ],
          "enums": null,
          "functions": [
            {
              "function_name": "rls_auto_enable",
              "arguments": "",
              "return_type": "event_trigger",
              "body": "\nDECLARE\n  cmd record;\nBEGIN\n  FOR cmd IN\n    SELECT *\n    FROM pg_event_trigger_ddl_commands()\n    WHERE command_tag IN ('CREATE TABLE', 'CREATE TABLE AS', 'SELECT INTO')\n      AND object_type IN ('table','partitioned table')\n  LOOP\n     IF cmd.schema_name IS NOT NULL AND cmd.schema_name IN ('public') AND cmd.schema_name NOT IN ('pg_catalog','information_schema') AND cmd.schema_name NOT LIKE 'pg_toast%' AND cmd.schema_name NOT LIKE 'pg_temp%' THEN\n      BEGIN\n        EXECUTE format('alter table if exists %s enable row level security', cmd.object_identity);\n        RAISE LOG 'rls_auto_enable: enabled RLS on %', cmd.object_identity;\n      EXCEPTION\n        WHEN OTHERS THEN\n          RAISE LOG 'rls_auto_enable: failed to enable RLS on %', cmd.object_identity;\n      END;\n     ELSE\n        RAISE LOG 'rls_auto_enable: skip % (either system schema or not in enforced list: %.)', cmd.object_identity, cmd.schema_name;\n     END IF;\n  END LOOP;\nEND;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "set_updated_at",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nBEGIN\n    NEW.updated_at = NOW();\n    RETURN NEW;\nEND;\n",
              "language": "plpgsql"
            }
          ]
        },
        {
          "schema_name": "retail",
          "tables": [
            {
              "table_name": "brand_affiliate_segment_members",
              "table_type": "BASE TABLE",
              "table_comment": "Cached segment membership — populated by SegmentEvaluationService, not manually managed.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "segment_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "affiliate_professional_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "rank",
                  "ordinal_position": 4,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Position in the ranked list (1 = top). 0 for unranked criteria like professional_type."
                },
                {
                  "column_name": "metric_value",
                  "ordinal_position": 5,
                  "data_type": "bigint",
                  "udt_name": "int8",
                  "character_maximum_length": null,
                  "numeric_precision": 64,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "The metric value used for ranking (revenue cents, order count, commission cents, or epoch seconds for newest)."
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 6,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_affiliate_segment_members_affiliate_professional_id_fkey",
                  "column_name": "affiliate_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "brand_affiliate_segment_members_segment_id_fkey",
                  "column_name": "segment_id",
                  "foreign_schema": "retail",
                  "foreign_table": "brand_affiliate_segments",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": [
                {
                  "uq_name": "brand_affiliate_segment_membe_segment_id_affiliate_professi_key",
                  "columns": [
                    "affiliate_professional_id",
                    "segment_id"
                  ]
                }
              ],
              "indexes": [
                {
                  "index_name": "brand_affiliate_segment_membe_segment_id_affiliate_professi_key",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "segment_id",
                    "affiliate_professional_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_affiliate_segment_membe_segment_id_affiliate_professi_key ON retail.brand_affiliate_segment_members USING btree (segment_id, affiliate_professional_id)"
                },
                {
                  "index_name": "brand_affiliate_segment_members_affiliate_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "affiliate_professional_id"
                  ],
                  "index_definition": "CREATE INDEX brand_affiliate_segment_members_affiliate_idx ON retail.brand_affiliate_segment_members USING btree (affiliate_professional_id)"
                },
                {
                  "index_name": "brand_affiliate_segment_members_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_affiliate_segment_members_pkey ON retail.brand_affiliate_segment_members USING btree (id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "brand_affiliate_segments",
              "table_type": "BASE TABLE",
              "table_comment": "Dynamic affiliate groupings defined by criteria. Membership is auto-computed from analytics data.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "name",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "description",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "criteria",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Ranking/filter criteria: highest_revenue, lowest_revenue, most_orders, fewest_orders, highest_commission, lowest_commission, newest, professional_type"
                },
                {
                  "column_name": "size",
                  "ordinal_position": 6,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "10",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Top N affiliates to include. 0 means empty segment."
                },
                {
                  "column_name": "lookback_days",
                  "ordinal_position": 7,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "NULL = all-time. Otherwise number of days to look back for analytics criteria."
                },
                {
                  "column_name": "professional_type_filter",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Required when criteria = professional_type. E.g. barber, salon, ambassador."
                },
                {
                  "column_name": "members_refreshed_at",
                  "ordinal_position": 9,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 10,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 11,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_affiliate_segments_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": [
                {
                  "uq_name": "brand_affiliate_segments_brand_professional_id_name_key",
                  "columns": [
                    "brand_professional_id",
                    "name"
                  ]
                }
              ],
              "indexes": [
                {
                  "index_name": "brand_affiliate_segments_brand_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id"
                  ],
                  "index_definition": "CREATE INDEX brand_affiliate_segments_brand_idx ON retail.brand_affiliate_segments USING btree (brand_professional_id)"
                },
                {
                  "index_name": "brand_affiliate_segments_brand_professional_id_name_key",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "name"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_affiliate_segments_brand_professional_id_name_key ON retail.brand_affiliate_segments USING btree (brand_professional_id, name)"
                },
                {
                  "index_name": "brand_affiliate_segments_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_affiliate_segments_pkey ON retail.brand_affiliate_segments USING btree (id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "brand_affiliate_settings",
              "table_type": "BASE TABLE",
              "table_comment": "Brand-managed per-affiliate settings (e.g. whether the affiliate can upload product media).",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "affiliate_professional_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "allow_affiliate_media",
                  "ordinal_position": 4,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "true",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 5,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 6,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_affiliate_settings_affiliate_professional_id_fkey",
                  "column_name": "affiliate_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "brand_affiliate_settings_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": [
                {
                  "uq_name": "brand_affiliate_settings_brand_professional_id_affiliate_pr_key",
                  "columns": [
                    "affiliate_professional_id",
                    "brand_professional_id"
                  ]
                }
              ],
              "indexes": [
                {
                  "index_name": "brand_affiliate_settings_affiliate_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "affiliate_professional_id"
                  ],
                  "index_definition": "CREATE INDEX brand_affiliate_settings_affiliate_idx ON retail.brand_affiliate_settings USING btree (affiliate_professional_id)"
                },
                {
                  "index_name": "brand_affiliate_settings_brand_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id"
                  ],
                  "index_definition": "CREATE INDEX brand_affiliate_settings_brand_idx ON retail.brand_affiliate_settings USING btree (brand_professional_id)"
                },
                {
                  "index_name": "brand_affiliate_settings_brand_professional_id_affiliate_pr_key",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "affiliate_professional_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_affiliate_settings_brand_professional_id_affiliate_pr_key ON retail.brand_affiliate_settings USING btree (brand_professional_id, affiliate_professional_id)"
                },
                {
                  "index_name": "brand_affiliate_settings_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_affiliate_settings_pkey ON retail.brand_affiliate_settings USING btree (id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "brand_commission_topups",
              "table_type": "BASE TABLE",
              "table_comment": "Manual commission funding top-ups made by brands; used to maintain per-brand payout wallet balances.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "stripe_checkout_session_id",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "stripe_payment_intent_id",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "amount_cents",
                  "ordinal_position": 5,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'AUD'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "status",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'completed'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 8,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 9,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_commission_topups_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": [
                {
                  "uq_name": "bct_unique_session",
                  "columns": [
                    "stripe_checkout_session_id"
                  ]
                }
              ],
              "indexes": [
                {
                  "index_name": "bct_unique_session",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "stripe_checkout_session_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX bct_unique_session ON retail.brand_commission_topups USING btree (stripe_checkout_session_id)"
                },
                {
                  "index_name": "brand_commission_topups_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_commission_topups_pkey ON retail.brand_commission_topups USING btree (id)"
                },
                {
                  "index_name": "idx_bct_brand",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id"
                  ],
                  "index_definition": "CREATE INDEX idx_bct_brand ON retail.brand_commission_topups USING btree (brand_professional_id)"
                },
                {
                  "index_name": "idx_bct_brand_created",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "created_at"
                  ],
                  "index_definition": "CREATE INDEX idx_bct_brand_created ON retail.brand_commission_topups USING btree (brand_professional_id, created_at DESC)"
                },
                {
                  "index_name": "idx_bct_payment_intent",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "stripe_payment_intent_id"
                  ],
                  "index_definition": "CREATE INDEX idx_bct_payment_intent ON retail.brand_commission_topups USING btree (stripe_payment_intent_id) WHERE (stripe_payment_intent_id IS NOT NULL)"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "brand_product_affiliate_overrides",
              "table_type": "BASE TABLE",
              "table_comment": "Per-affiliate product access overrides. 'deny' always blocks access; 'allow' bypasses brand-level availability for a specific affiliate.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "affiliate_professional_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_product_id",
                  "ordinal_position": 4,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "override_type",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'deny'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 6,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 7,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_product_affiliate_override_affiliate_professional_id_fkey",
                  "column_name": "affiliate_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "brand_product_affiliate_overrides_brand_product_id_fkey",
                  "column_name": "brand_product_id",
                  "foreign_schema": "retail",
                  "foreign_table": "brand_products",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "brand_product_affiliate_overrides_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "bpao_affiliate_product_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "affiliate_professional_id",
                    "brand_product_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX bpao_affiliate_product_uq ON retail.brand_product_affiliate_overrides USING btree (affiliate_professional_id, brand_product_id)"
                },
                {
                  "index_name": "bpao_brand_affiliate_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "affiliate_professional_id"
                  ],
                  "index_definition": "CREATE INDEX bpao_brand_affiliate_idx ON retail.brand_product_affiliate_overrides USING btree (brand_professional_id, affiliate_professional_id)"
                },
                {
                  "index_name": "brand_product_affiliate_overrides_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_product_affiliate_overrides_pkey ON retail.brand_product_affiliate_overrides USING btree (id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "brand_product_affiliate_settings",
              "table_type": "BASE TABLE",
              "table_comment": "Brand-controlled per-affiliate pricing overrides. NULL fields fall back to brand_product_settings values.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "affiliate_professional_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_product_id",
                  "ordinal_position": 4,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "commission_override",
                  "ordinal_position": 5,
                  "data_type": "numeric",
                  "udt_name": "numeric",
                  "character_maximum_length": null,
                  "numeric_precision": 5,
                  "numeric_scale": 2,
                  "is_nullable": "YES",
                  "column_default": "NULL::numeric",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Affiliate-specific commission rate (0-100%). Highest-priority tier; overrides brand_product_settings.commission_override and brand_store_settings.default_commission_rate."
                },
                {
                  "column_name": "discount_rate",
                  "ordinal_position": 6,
                  "data_type": "numeric",
                  "udt_name": "numeric",
                  "character_maximum_length": null,
                  "numeric_precision": 5,
                  "numeric_scale": 2,
                  "is_nullable": "YES",
                  "column_default": "NULL::numeric",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Affiliate-specific discount rate (0-100%). Overrides brand_product_settings.discount_rate when set."
                },
                {
                  "column_name": "custom_price",
                  "ordinal_position": 7,
                  "data_type": "numeric",
                  "udt_name": "numeric",
                  "character_maximum_length": null,
                  "numeric_precision": 10,
                  "numeric_scale": 2,
                  "is_nullable": "YES",
                  "column_default": "NULL::numeric",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Affiliate-specific fixed price. Overrides brand_product_settings.custom_price when set."
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 8,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 9,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_product_affiliate_settings_affiliate_professional_id_fkey",
                  "column_name": "affiliate_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "brand_product_affiliate_settings_brand_product_id_fkey",
                  "column_name": "brand_product_id",
                  "foreign_schema": "retail",
                  "foreign_table": "brand_products",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "brand_product_affiliate_settings_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": [
                {
                  "uq_name": "bpas_unique",
                  "columns": [
                    "affiliate_professional_id",
                    "brand_product_id"
                  ]
                }
              ],
              "indexes": [
                {
                  "index_name": "bpas_brand_affiliate_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "affiliate_professional_id"
                  ],
                  "index_definition": "CREATE INDEX bpas_brand_affiliate_idx ON retail.brand_product_affiliate_settings USING btree (brand_professional_id, affiliate_professional_id)"
                },
                {
                  "index_name": "bpas_unique",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "affiliate_professional_id",
                    "brand_product_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX bpas_unique ON retail.brand_product_affiliate_settings USING btree (affiliate_professional_id, brand_product_id)"
                },
                {
                  "index_name": "brand_product_affiliate_settings_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_product_affiliate_settings_pkey ON retail.brand_product_affiliate_settings USING btree (id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "brand_product_media",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_product_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "site_media_id",
                  "ordinal_position": 4,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "sort_order",
                  "ordinal_position": 5,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 6,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 7,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_product_media_brand_product_id_fkey",
                  "column_name": "brand_product_id",
                  "foreign_schema": "retail",
                  "foreign_table": "brand_products",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "brand_product_media_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "brand_product_media_site_media_id_fkey",
                  "column_name": "site_media_id",
                  "foreign_schema": "core",
                  "foreign_table": "site_media",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": [
                {
                  "uq_name": "brand_product_media_brand_product_id_professional_id_site_m_key",
                  "columns": [
                    "brand_product_id",
                    "professional_id",
                    "site_media_id"
                  ]
                }
              ],
              "indexes": [
                {
                  "index_name": "brand_product_media_brand_product_id_professional_id_site_m_key",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "brand_product_id",
                    "professional_id",
                    "site_media_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_product_media_brand_product_id_professional_id_site_m_key ON retail.brand_product_media USING btree (brand_product_id, professional_id, site_media_id)"
                },
                {
                  "index_name": "brand_product_media_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_product_media_pkey ON retail.brand_product_media USING btree (id)"
                },
                {
                  "index_name": "brand_product_media_product_pro_sort_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_product_id",
                    "professional_id",
                    "sort_order"
                  ],
                  "index_definition": "CREATE INDEX brand_product_media_product_pro_sort_idx ON retail.brand_product_media USING btree (brand_product_id, professional_id, sort_order)"
                },
                {
                  "index_name": "brand_product_media_professional_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id"
                  ],
                  "index_definition": "CREATE INDEX brand_product_media_professional_idx ON retail.brand_product_media USING btree (professional_id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "brand_product_settings",
              "table_type": "BASE TABLE",
              "table_comment": "Per-product settings for brand accounts: custom commission rate, discount %, and featured flag.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "shopify_product_id",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "commission_override",
                  "ordinal_position": 4,
                  "data_type": "numeric",
                  "udt_name": "numeric",
                  "character_maximum_length": null,
                  "numeric_precision": 5,
                  "numeric_scale": 2,
                  "is_nullable": "YES",
                  "column_default": "NULL::numeric",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Per-product commission rate (0-100%). Must be >= brand default_commission_rate. NULL = use default."
                },
                {
                  "column_name": "discount_rate",
                  "ordinal_position": 5,
                  "data_type": "numeric",
                  "udt_name": "numeric",
                  "character_maximum_length": null,
                  "numeric_precision": 5,
                  "numeric_scale": 2,
                  "is_nullable": "YES",
                  "column_default": "NULL::numeric",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Discount applied to this product price for affiliates (0-100%). NULL = no discount."
                },
                {
                  "column_name": "is_featured",
                  "ordinal_position": 6,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "false",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Whether this product is default-featured for new affiliates (max 10 per brand)."
                },
                {
                  "column_name": "sort_order",
                  "ordinal_position": 7,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 8,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 9,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "is_available",
                  "ordinal_position": 10,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "true",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Whether this product is active (visible to affiliates). true = Active, false = Disabled. Disabled products are hidden from affiliate storefronts."
                },
                {
                  "column_name": "custom_price",
                  "ordinal_position": 11,
                  "data_type": "numeric",
                  "udt_name": "numeric",
                  "character_maximum_length": null,
                  "numeric_precision": 10,
                  "numeric_scale": 2,
                  "is_nullable": "YES",
                  "column_default": "NULL::numeric",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Optional fixed price override displayed to affiliates instead of the Shopify price. NULL = use Shopify price."
                },
                {
                  "column_name": "brand_product_id",
                  "ordinal_position": 12,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "allow_affiliate_media",
                  "ordinal_position": 14,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "true",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "When false, affiliates cannot upload custom photos for this specific product."
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_product_settings_brand_product_id_fkey",
                  "column_name": "brand_product_id",
                  "foreign_schema": "retail",
                  "foreign_table": "brand_products",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "brand_product_settings_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": [
                {
                  "uq_name": "brand_product_settings_professional_id_shopify_product_id_key",
                  "columns": [
                    "professional_id",
                    "shopify_product_id"
                  ]
                }
              ],
              "indexes": [
                {
                  "index_name": "bps_is_available",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "is_available"
                  ],
                  "index_definition": "CREATE INDEX bps_is_available ON retail.brand_product_settings USING btree (professional_id, is_available)"
                },
                {
                  "index_name": "bps_professional_brand_product_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "brand_product_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX bps_professional_brand_product_uq ON retail.brand_product_settings USING btree (professional_id, brand_product_id)"
                },
                {
                  "index_name": "bps_professional_featured",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "is_featured"
                  ],
                  "index_definition": "CREATE INDEX bps_professional_featured ON retail.brand_product_settings USING btree (professional_id, is_featured)"
                },
                {
                  "index_name": "bps_professional_id",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id"
                  ],
                  "index_definition": "CREATE INDEX bps_professional_id ON retail.brand_product_settings USING btree (professional_id)"
                },
                {
                  "index_name": "brand_product_settings_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_product_settings_pkey ON retail.brand_product_settings USING btree (id)"
                },
                {
                  "index_name": "brand_product_settings_professional_id_shopify_product_id_key",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "shopify_product_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_product_settings_professional_id_shopify_product_id_key ON retail.brand_product_settings USING btree (professional_id, shopify_product_id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "brand_products",
              "table_type": "BASE TABLE",
              "table_comment": "Full Shopify-synced catalog for each brand professional account.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "enterprise_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "shopify_product_id",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "title",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "handle",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "product_url",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "image_url",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "price_cents",
                  "ordinal_position": 9,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 10,
                  "data_type": "character",
                  "udt_name": "bpchar",
                  "character_maximum_length": 3,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'AUD'::bpchar",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "shopify_status",
                  "ordinal_position": 11,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'active'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "is_sync_active",
                  "ordinal_position": 12,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "true",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "last_synced_at",
                  "ordinal_position": 13,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "metadata",
                  "ordinal_position": 14,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 15,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 16,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "description",
                  "ordinal_position": 17,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Plain-text product description from Shopify."
                },
                {
                  "column_name": "product_type",
                  "ordinal_position": 18,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Shopify product type classification."
                },
                {
                  "column_name": "tags",
                  "ordinal_position": 19,
                  "data_type": "ARRAY",
                  "udt_name": "_text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::text[]",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Array of Shopify product tags."
                },
                {
                  "column_name": "images",
                  "ordinal_position": 20,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'[]'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "JSON array of {url, altText} objects from Shopify images."
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_products_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "brand_products_enterprise_id_fkey",
                  "column_name": "enterprise_id",
                  "foreign_schema": "core",
                  "foreign_table": "enterprises",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "brand_products_brand_shopify_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "shopify_product_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_products_brand_shopify_uq ON retail.brand_products USING btree (brand_professional_id, shopify_product_id)"
                },
                {
                  "index_name": "brand_products_brand_sync_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "is_sync_active"
                  ],
                  "index_definition": "CREATE INDEX brand_products_brand_sync_idx ON retail.brand_products USING btree (brand_professional_id, is_sync_active)"
                },
                {
                  "index_name": "brand_products_enterprise_sync_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "enterprise_id",
                    "is_sync_active"
                  ],
                  "index_definition": "CREATE INDEX brand_products_enterprise_sync_idx ON retail.brand_products USING btree (enterprise_id, is_sync_active)"
                },
                {
                  "index_name": "brand_products_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_products_pkey ON retail.brand_products USING btree (id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "brand_promotions",
              "table_type": "BASE TABLE",
              "table_comment": "Time-bounded commission/discount promotions with affiliate and product targeting.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "name",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "description",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "starts_at",
                  "ordinal_position": 5,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "ends_at",
                  "ordinal_position": 6,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "commission_rate",
                  "ordinal_position": 7,
                  "data_type": "numeric",
                  "udt_name": "numeric",
                  "character_maximum_length": null,
                  "numeric_precision": 5,
                  "numeric_scale": 2,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "discount_rate",
                  "ordinal_position": 8,
                  "data_type": "numeric",
                  "udt_name": "numeric",
                  "character_maximum_length": null,
                  "numeric_precision": 5,
                  "numeric_scale": 2,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "affiliate_scope",
                  "ordinal_position": 9,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'all'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "all = all affiliates; segments = specific segment IDs; affiliates = specific affiliate IDs"
                },
                {
                  "column_name": "affiliate_segment_ids",
                  "ordinal_position": 10,
                  "data_type": "ARRAY",
                  "udt_name": "_uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::uuid[]",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "affiliate_ids",
                  "ordinal_position": 11,
                  "data_type": "ARRAY",
                  "udt_name": "_uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::uuid[]",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "product_scope",
                  "ordinal_position": 12,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'all'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "all = all products; products = specific product IDs"
                },
                {
                  "column_name": "product_ids",
                  "ordinal_position": 13,
                  "data_type": "ARRAY",
                  "udt_name": "_uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::uuid[]",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "priority",
                  "ordinal_position": 14,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Higher number wins when multiple promotions overlap. Range 0-100."
                },
                {
                  "column_name": "is_active",
                  "ordinal_position": 15,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "true",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "notification_sent_at",
                  "ordinal_position": 16,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "end_notification_sent_at",
                  "ordinal_position": 17,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 18,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 19,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_promotions_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "brand_promotions_affiliate_ids_gin",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "affiliate_ids"
                  ],
                  "index_definition": "CREATE INDEX brand_promotions_affiliate_ids_gin ON retail.brand_promotions USING gin (affiliate_ids)"
                },
                {
                  "index_name": "brand_promotions_affiliate_segment_ids_gin",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "affiliate_segment_ids"
                  ],
                  "index_definition": "CREATE INDEX brand_promotions_affiliate_segment_ids_gin ON retail.brand_promotions USING gin (affiliate_segment_ids)"
                },
                {
                  "index_name": "brand_promotions_brand_active_dates_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "starts_at",
                    "ends_at",
                    "is_active"
                  ],
                  "index_definition": "CREATE INDEX brand_promotions_brand_active_dates_idx ON retail.brand_promotions USING btree (brand_professional_id, is_active, starts_at, ends_at)"
                },
                {
                  "index_name": "brand_promotions_brand_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id"
                  ],
                  "index_definition": "CREATE INDEX brand_promotions_brand_idx ON retail.brand_promotions USING btree (brand_professional_id)"
                },
                {
                  "index_name": "brand_promotions_end_notify_due_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "id",
                    "ends_at"
                  ],
                  "index_definition": "CREATE INDEX brand_promotions_end_notify_due_idx ON retail.brand_promotions USING btree (ends_at, id) WHERE ((is_active = true) AND (end_notification_sent_at IS NULL))"
                },
                {
                  "index_name": "brand_promotions_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_promotions_pkey ON retail.brand_promotions USING btree (id)"
                },
                {
                  "index_name": "brand_promotions_product_ids_gin",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "product_ids"
                  ],
                  "index_definition": "CREATE INDEX brand_promotions_product_ids_gin ON retail.brand_promotions USING gin (product_ids)"
                },
                {
                  "index_name": "brand_promotions_start_notify_due_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "id",
                    "starts_at"
                  ],
                  "index_definition": "CREATE INDEX brand_promotions_start_notify_due_idx ON retail.brand_promotions USING btree (starts_at, id) WHERE ((is_active = true) AND (notification_sent_at IS NULL))"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "brand_store_settings",
              "table_type": "BASE TABLE",
              "table_comment": "Brand-level store config: default commission rate applied to all affiliates unless overridden per product.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "default_commission_rate",
                  "ordinal_position": 3,
                  "data_type": "numeric",
                  "udt_name": "numeric",
                  "character_maximum_length": null,
                  "numeric_precision": 5,
                  "numeric_scale": 2,
                  "is_nullable": "NO",
                  "column_default": "15",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 4,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 5,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "favourite_brand_product_ids",
                  "ordinal_position": 6,
                  "data_type": "ARRAY",
                  "udt_name": "_uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::uuid[]",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Brand-managed favourite brand_product_id list used for default favourites."
                },
                {
                  "column_name": "checkout_mode",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'shopify'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Controls storefront checkout handling for this brand. shopify = Shopify-hosted checkout, stripe = Comet Stripe checkout with Shopify order sync."
                },
                {
                  "column_name": "payout_hold_days",
                  "ordinal_position": 8,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Brand-specific payout hold period in days. NULL = use system default. Minimum enforced by app is 7 days."
                },
                {
                  "column_name": "default_affiliate_theme_id",
                  "ordinal_position": 9,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Theme assigned to new affiliates when they connect to this brand."
                },
                {
                  "column_name": "default_affiliate_product_ids",
                  "ordinal_position": 10,
                  "data_type": "ARRAY",
                  "udt_name": "_uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::uuid[]",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Up to 10 product IDs used as defaults for new affiliates (separate from favourite_brand_product_ids)."
                },
                {
                  "column_name": "allow_affiliate_media",
                  "ordinal_position": 11,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "true",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "When false, affiliates cannot upload custom product photos for any of this brand's products."
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_store_settings_default_affiliate_theme_id_fkey",
                  "column_name": "default_affiliate_theme_id",
                  "foreign_schema": "core",
                  "foreign_table": "themes",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                },
                {
                  "fk_name": "brand_store_settings_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": [
                {
                  "uq_name": "brand_store_settings_professional_id_key",
                  "columns": [
                    "professional_id"
                  ]
                }
              ],
              "indexes": [
                {
                  "index_name": "brand_store_settings_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_store_settings_pkey ON retail.brand_store_settings USING btree (id)"
                },
                {
                  "index_name": "brand_store_settings_professional_id_key",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_store_settings_professional_id_key ON retail.brand_store_settings USING btree (professional_id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "brand_team_memberships",
              "table_type": "BASE TABLE",
              "table_comment": "Brand team membership role assignments used by brand analytics and store RBAC.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "member_professional_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "role",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'read_only'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "status",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'active'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 6,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 7,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "brand_team_memberships_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "brand_team_memberships_member_professional_id_fkey",
                  "column_name": "member_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "brand_team_memberships_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX brand_team_memberships_pkey ON retail.brand_team_memberships USING btree (id)"
                },
                {
                  "index_name": "btm_active_brand_member_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "member_professional_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX btm_active_brand_member_uq ON retail.brand_team_memberships USING btree (brand_professional_id, member_professional_id) WHERE (status = 'active'::text)"
                },
                {
                  "index_name": "btm_brand_status_role_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "role",
                    "status"
                  ],
                  "index_definition": "CREATE INDEX btm_brand_status_role_idx ON retail.brand_team_memberships USING btree (brand_professional_id, status, role)"
                },
                {
                  "index_name": "btm_member_status_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "member_professional_id",
                    "status"
                  ],
                  "index_definition": "CREATE INDEX btm_member_status_idx ON retail.brand_team_memberships USING btree (member_professional_id, status)"
                },
                {
                  "index_name": "btm_single_active_owner_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX btm_single_active_owner_uq ON retail.brand_team_memberships USING btree (brand_professional_id) WHERE ((status = 'active'::text) AND (role = 'owner'::text))"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "checkout_sessions",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "token",
                  "ordinal_position": 2,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "affiliate_professional_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 4,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "site_id",
                  "ordinal_position": 5,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "status",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'active'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "expires_at",
                  "ordinal_position": 7,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "converted_at",
                  "ordinal_position": 8,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "last_seen_at",
                  "ordinal_position": 9,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "context_snapshot",
                  "ordinal_position": 10,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 11,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 12,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "checkout_sessions_affiliate_professional_id_fkey",
                  "column_name": "affiliate_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "checkout_sessions_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "checkout_sessions_site_id_fkey",
                  "column_name": "site_id",
                  "foreign_schema": "core",
                  "foreign_table": "sites",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "checkout_sessions_affiliate_status_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "affiliate_professional_id",
                    "status"
                  ],
                  "index_definition": "CREATE INDEX checkout_sessions_affiliate_status_idx ON retail.checkout_sessions USING btree (affiliate_professional_id, status)"
                },
                {
                  "index_name": "checkout_sessions_brand_status_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "status"
                  ],
                  "index_definition": "CREATE INDEX checkout_sessions_brand_status_idx ON retail.checkout_sessions USING btree (brand_professional_id, status)"
                },
                {
                  "index_name": "checkout_sessions_expires_status_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "status",
                    "expires_at"
                  ],
                  "index_definition": "CREATE INDEX checkout_sessions_expires_status_idx ON retail.checkout_sessions USING btree (expires_at, status) WHERE (status = ANY (ARRAY['active'::text, 'expired'::text]))"
                },
                {
                  "index_name": "checkout_sessions_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX checkout_sessions_pkey ON retail.checkout_sessions USING btree (id)"
                },
                {
                  "index_name": "checkout_sessions_token_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "token"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX checkout_sessions_token_uq ON retail.checkout_sessions USING btree (token)"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "commission_ledger_entries",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "order_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "order_item_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 4,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "affiliate_professional_id",
                  "ordinal_position": 5,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "entry_type",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "status",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'pending'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "amount_cents",
                  "ordinal_position": 9,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 10,
                  "data_type": "character",
                  "udt_name": "bpchar",
                  "character_maximum_length": 3,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'AUD'::bpchar",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "commission_rate",
                  "ordinal_position": 11,
                  "data_type": "numeric",
                  "udt_name": "numeric",
                  "character_maximum_length": null,
                  "numeric_precision": 7,
                  "numeric_scale": 4,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "rate_source",
                  "ordinal_position": 12,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "idempotency_key",
                  "ordinal_position": 13,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "calculation_metadata",
                  "ordinal_position": 14,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "occurred_at",
                  "ordinal_position": 15,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 16,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 17,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "payout_id",
                  "ordinal_position": 18,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "commission_ledger_entries_affiliate_professional_id_fkey",
                  "column_name": "affiliate_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "commission_ledger_entries_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "commission_ledger_entries_order_id_fkey",
                  "column_name": "order_id",
                  "foreign_schema": "retail",
                  "foreign_table": "orders",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                },
                {
                  "fk_name": "commission_ledger_entries_order_item_id_fkey",
                  "column_name": "order_item_id",
                  "foreign_schema": "retail",
                  "foreign_table": "order_items",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                },
                {
                  "fk_name": "commission_ledger_entries_payout_id_fkey",
                  "column_name": "payout_id",
                  "foreign_schema": "retail",
                  "foreign_table": "commission_payouts",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "commission_ledger_entries_affiliate_status_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "affiliate_professional_id",
                    "status",
                    "occurred_at"
                  ],
                  "index_definition": "CREATE INDEX commission_ledger_entries_affiliate_status_idx ON retail.commission_ledger_entries USING btree (affiliate_professional_id, status, occurred_at DESC)"
                },
                {
                  "index_name": "commission_ledger_entries_brand_status_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "status",
                    "occurred_at"
                  ],
                  "index_definition": "CREATE INDEX commission_ledger_entries_brand_status_idx ON retail.commission_ledger_entries USING btree (brand_professional_id, status, occurred_at DESC)"
                },
                {
                  "index_name": "commission_ledger_entries_idempotency_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "idempotency_key"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX commission_ledger_entries_idempotency_uq ON retail.commission_ledger_entries USING btree (idempotency_key)"
                },
                {
                  "index_name": "commission_ledger_entries_order_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "order_id",
                    "order_item_id"
                  ],
                  "index_definition": "CREATE INDEX commission_ledger_entries_order_idx ON retail.commission_ledger_entries USING btree (order_id, order_item_id)"
                },
                {
                  "index_name": "commission_ledger_entries_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX commission_ledger_entries_pkey ON retail.commission_ledger_entries USING btree (id)"
                },
                {
                  "index_name": "idx_cle_payout",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "payout_id"
                  ],
                  "index_definition": "CREATE INDEX idx_cle_payout ON retail.commission_ledger_entries USING btree (payout_id) WHERE (payout_id IS NOT NULL)"
                },
                {
                  "index_name": "idx_cle_promotion_id",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [],
                  "index_definition": "CREATE INDEX idx_cle_promotion_id ON retail.commission_ledger_entries USING btree (((calculation_metadata ->> 'promotion_id'::text))) WHERE ((calculation_metadata ->> 'promotion_id'::text) IS NOT NULL)"
                },
                {
                  "index_name": "idx_cle_unpaid",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "affiliate_professional_id",
                    "currency_code"
                  ],
                  "index_definition": "CREATE INDEX idx_cle_unpaid ON retail.commission_ledger_entries USING btree (brand_professional_id, affiliate_professional_id, currency_code) WHERE ((payout_id IS NULL) AND (entry_type = 'accrual'::text) AND (status = 'approved'::text))"
                },
                {
                  "index_name": "idx_cle_unpaid_reversals",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "affiliate_professional_id",
                    "currency_code"
                  ],
                  "index_definition": "CREATE INDEX idx_cle_unpaid_reversals ON retail.commission_ledger_entries USING btree (brand_professional_id, affiliate_professional_id, currency_code) WHERE ((payout_id IS NULL) AND (entry_type = 'reversal'::text) AND (status = 'approved'::text))"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "commission_payout_items",
              "table_type": "BASE TABLE",
              "table_comment": "Links individual commission ledger entries to the payout batch they were settled in. Each entry can only be paid out once.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "payout_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "commission_ledger_entry_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "amount_cents",
                  "ordinal_position": 4,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 5,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "commission_payout_items_commission_ledger_entry_id_fkey",
                  "column_name": "commission_ledger_entry_id",
                  "foreign_schema": "retail",
                  "foreign_table": "commission_ledger_entries",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "RESTRICT"
                },
                {
                  "fk_name": "commission_payout_items_payout_id_fkey",
                  "column_name": "payout_id",
                  "foreign_schema": "retail",
                  "foreign_table": "commission_payouts",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": [
                {
                  "uq_name": "cpi_unique_entry",
                  "columns": [
                    "commission_ledger_entry_id"
                  ]
                }
              ],
              "indexes": [
                {
                  "index_name": "commission_payout_items_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX commission_payout_items_pkey ON retail.commission_payout_items USING btree (id)"
                },
                {
                  "index_name": "cpi_unique_entry",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "commission_ledger_entry_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX cpi_unique_entry ON retail.commission_payout_items USING btree (commission_ledger_entry_id)"
                },
                {
                  "index_name": "idx_cpi_payout",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "payout_id"
                  ],
                  "index_definition": "CREATE INDEX idx_cpi_payout ON retail.commission_payout_items USING btree (payout_id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "commission_payouts",
              "table_type": "BASE TABLE",
              "table_comment": "Batched commission payout from a brand to an affiliate. One row per (brand, affiliate, currency) batch.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "affiliate_professional_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "stripe_payment_intent_id",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "stripe_transfer_id",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "status",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'pending'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "gross_commission_cents",
                  "ordinal_position": 7,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "platform_fee_cents",
                  "ordinal_position": 8,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "net_payout_cents",
                  "ordinal_position": 9,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 10,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'AUD'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "failure_reason",
                  "ordinal_position": 11,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "failure_code",
                  "ordinal_position": 12,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "ledger_entry_count",
                  "ordinal_position": 13,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "eligible_after",
                  "ordinal_position": 14,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "processed_at",
                  "ordinal_position": 15,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 16,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 17,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "funding_source",
                  "ordinal_position": 18,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "wallet_debit_cents",
                  "ordinal_position": 19,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "charge_cents",
                  "ordinal_position": 20,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "commission_payouts_affiliate_professional_id_fkey",
                  "column_name": "affiliate_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "RESTRICT"
                },
                {
                  "fk_name": "commission_payouts_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "RESTRICT"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "commission_payouts_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX commission_payouts_pkey ON retail.commission_payouts USING btree (id)"
                },
                {
                  "index_name": "idx_cp_affiliate",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "affiliate_professional_id"
                  ],
                  "index_definition": "CREATE INDEX idx_cp_affiliate ON retail.commission_payouts USING btree (affiliate_professional_id)"
                },
                {
                  "index_name": "idx_cp_brand",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id"
                  ],
                  "index_definition": "CREATE INDEX idx_cp_brand ON retail.commission_payouts USING btree (brand_professional_id)"
                },
                {
                  "index_name": "idx_cp_pending_eligible",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "eligible_after"
                  ],
                  "index_definition": "CREATE INDEX idx_cp_pending_eligible ON retail.commission_payouts USING btree (eligible_after) WHERE ((status = 'pending'::text) AND (processed_at IS NULL))"
                },
                {
                  "index_name": "idx_cp_status_eligible",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "status",
                    "eligible_after"
                  ],
                  "index_definition": "CREATE INDEX idx_cp_status_eligible ON retail.commission_payouts USING btree (status, eligible_after) WHERE (status = 'pending'::text)"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "enterprise_brands",
              "table_type": "BASE TABLE",
              "table_comment": "Brands managed by promoter enterprises.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "enterprise_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "name",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "slug",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "description",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "is_active",
                  "ordinal_position": 6,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "true",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "metadata",
                  "ordinal_position": 7,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 8,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 9,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "enterprise_brands_enterprise_id_fkey",
                  "column_name": "enterprise_id",
                  "foreign_schema": "core",
                  "foreign_table": "enterprises",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "eb_enterprise_active_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "enterprise_id",
                    "is_active"
                  ],
                  "index_definition": "CREATE INDEX eb_enterprise_active_idx ON retail.enterprise_brands USING btree (enterprise_id, is_active)"
                },
                {
                  "index_name": "eb_enterprise_name_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "enterprise_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX eb_enterprise_name_uq ON retail.enterprise_brands USING btree (enterprise_id, lower(name))"
                },
                {
                  "index_name": "eb_enterprise_slug_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "enterprise_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX eb_enterprise_slug_uq ON retail.enterprise_brands USING btree (enterprise_id, lower(slug)) WHERE (slug IS NOT NULL)"
                },
                {
                  "index_name": "enterprise_brands_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX enterprise_brands_pkey ON retail.enterprise_brands USING btree (id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "enterprise_products",
              "table_type": "BASE TABLE",
              "table_comment": "Promoter-enterprise product catalog sourced from connected Shopify accounts.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "enterprise_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "shopify_account_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_id",
                  "ordinal_position": 4,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "shopify_product_id",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "title",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "handle",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "product_url",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "image_url",
                  "ordinal_position": 9,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "price_cents",
                  "ordinal_position": 10,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 11,
                  "data_type": "character",
                  "udt_name": "bpchar",
                  "character_maximum_length": 3,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'AUD'::bpchar",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "is_active",
                  "ordinal_position": 12,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "true",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "metadata",
                  "ordinal_position": 13,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 14,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 15,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "enterprise_products_brand_id_fkey",
                  "column_name": "brand_id",
                  "foreign_schema": "retail",
                  "foreign_table": "enterprise_brands",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                },
                {
                  "fk_name": "enterprise_products_enterprise_id_fkey",
                  "column_name": "enterprise_id",
                  "foreign_schema": "core",
                  "foreign_table": "enterprises",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "enterprise_products_shopify_account_id_fkey",
                  "column_name": "shopify_account_id",
                  "foreign_schema": "retail",
                  "foreign_table": "enterprise_shopify_accounts",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "enterprise_products_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX enterprise_products_pkey ON retail.enterprise_products USING btree (id)"
                },
                {
                  "index_name": "ep_enterprise_active_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "enterprise_id",
                    "is_active",
                    "created_at"
                  ],
                  "index_definition": "CREATE INDEX ep_enterprise_active_idx ON retail.enterprise_products USING btree (enterprise_id, is_active, created_at DESC)"
                },
                {
                  "index_name": "ep_enterprise_shopify_product_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "enterprise_id",
                    "shopify_product_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX ep_enterprise_shopify_product_uq ON retail.enterprise_products USING btree (enterprise_id, shopify_product_id)"
                },
                {
                  "index_name": "ep_shopify_account_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "shopify_account_id",
                    "created_at"
                  ],
                  "index_definition": "CREATE INDEX ep_shopify_account_idx ON retail.enterprise_products USING btree (shopify_account_id, created_at DESC)"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "enterprise_shopify_accounts",
              "table_type": "BASE TABLE",
              "table_comment": "Shopify shops connected to promoter enterprises.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "enterprise_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "shop_domain",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "shop_name",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "external_shop_id",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "token_reference",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": "Reference to secure token storage (do not store raw Shopify token in plain text)."
                },
                {
                  "column_name": "is_primary",
                  "ordinal_position": 7,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "false",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "is_active",
                  "ordinal_position": 8,
                  "data_type": "boolean",
                  "udt_name": "bool",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "true",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "connected_at",
                  "ordinal_position": 9,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "metadata",
                  "ordinal_position": 10,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 11,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 12,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "enterprise_shopify_accounts_enterprise_id_fkey",
                  "column_name": "enterprise_id",
                  "foreign_schema": "core",
                  "foreign_table": "enterprises",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "enterprise_shopify_accounts_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX enterprise_shopify_accounts_pkey ON retail.enterprise_shopify_accounts USING btree (id)"
                },
                {
                  "index_name": "esa_enterprise_active_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "enterprise_id",
                    "is_active"
                  ],
                  "index_definition": "CREATE INDEX esa_enterprise_active_idx ON retail.enterprise_shopify_accounts USING btree (enterprise_id, is_active)"
                },
                {
                  "index_name": "esa_enterprise_external_shop_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "enterprise_id",
                    "external_shop_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX esa_enterprise_external_shop_uq ON retail.enterprise_shopify_accounts USING btree (enterprise_id, external_shop_id) WHERE (external_shop_id IS NOT NULL)"
                },
                {
                  "index_name": "esa_one_primary_per_enterprise_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "enterprise_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX esa_one_primary_per_enterprise_uq ON retail.enterprise_shopify_accounts USING btree (enterprise_id) WHERE ((is_primary = true) AND (is_active = true))"
                },
                {
                  "index_name": "esa_shop_domain_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [],
                  "index_definition": "CREATE UNIQUE INDEX esa_shop_domain_uq ON retail.enterprise_shopify_accounts USING btree (lower(shop_domain))"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "order_event_inbox",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "source",
                  "ordinal_position": 2,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "external_event_id",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "event_type",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "shop_domain",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "integration_id",
                  "ordinal_position": 6,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 7,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "payload",
                  "ordinal_position": 8,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "headers",
                  "ordinal_position": 9,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "status",
                  "ordinal_position": 10,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'pending'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "attempts",
                  "ordinal_position": 11,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "received_at",
                  "ordinal_position": 12,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "processed_at",
                  "ordinal_position": 13,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "rejection_reason",
                  "ordinal_position": 14,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "last_error",
                  "ordinal_position": 15,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 16,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 17,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "order_event_inbox_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                },
                {
                  "fk_name": "order_event_inbox_integration_id_fkey",
                  "column_name": "integration_id",
                  "foreign_schema": "core",
                  "foreign_table": "professional_integrations",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "order_event_inbox_brand_status_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "status",
                    "received_at"
                  ],
                  "index_definition": "CREATE INDEX order_event_inbox_brand_status_idx ON retail.order_event_inbox USING btree (brand_professional_id, status, received_at DESC)"
                },
                {
                  "index_name": "order_event_inbox_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX order_event_inbox_pkey ON retail.order_event_inbox USING btree (id)"
                },
                {
                  "index_name": "order_event_inbox_shop_domain_status_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "shop_domain",
                    "status"
                  ],
                  "index_definition": "CREATE INDEX order_event_inbox_shop_domain_status_idx ON retail.order_event_inbox USING btree (shop_domain, status)"
                },
                {
                  "index_name": "order_event_inbox_source_external_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "source",
                    "external_event_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX order_event_inbox_source_external_uq ON retail.order_event_inbox USING btree (source, external_event_id)"
                },
                {
                  "index_name": "order_event_inbox_status_received_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "status",
                    "received_at"
                  ],
                  "index_definition": "CREATE INDEX order_event_inbox_status_received_idx ON retail.order_event_inbox USING btree (status, received_at DESC)"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "order_items",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "order_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 3,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_product_id",
                  "ordinal_position": 4,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "shopify_line_item_id",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "shopify_product_id",
                  "ordinal_position": 6,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "shopify_variant_id",
                  "ordinal_position": 7,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "title",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "variant_title",
                  "ordinal_position": 9,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "sku",
                  "ordinal_position": 10,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "quantity",
                  "ordinal_position": 11,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "1",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "gross_line_cents",
                  "ordinal_position": 12,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "discount_line_cents",
                  "ordinal_position": 13,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "refunded_line_cents",
                  "ordinal_position": 14,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "returned_line_cents",
                  "ordinal_position": 15,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "net_line_cents",
                  "ordinal_position": 16,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 17,
                  "data_type": "character",
                  "udt_name": "bpchar",
                  "character_maximum_length": 3,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'AUD'::bpchar",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "product_snapshot",
                  "ordinal_position": 18,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 19,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 20,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "order_items_brand_product_id_fkey",
                  "column_name": "brand_product_id",
                  "foreign_schema": "retail",
                  "foreign_table": "brand_products",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                },
                {
                  "fk_name": "order_items_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "order_items_order_id_fkey",
                  "column_name": "order_id",
                  "foreign_schema": "retail",
                  "foreign_table": "orders",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "order_items_brand_created_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "created_at"
                  ],
                  "index_definition": "CREATE INDEX order_items_brand_created_idx ON retail.order_items USING btree (brand_professional_id, created_at DESC)"
                },
                {
                  "index_name": "order_items_brand_product_created_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_product_id",
                    "created_at"
                  ],
                  "index_definition": "CREATE INDEX order_items_brand_product_created_idx ON retail.order_items USING btree (brand_product_id, created_at DESC)"
                },
                {
                  "index_name": "order_items_order_line_item_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "order_id",
                    "shopify_line_item_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX order_items_order_line_item_uq ON retail.order_items USING btree (order_id, shopify_line_item_id)"
                },
                {
                  "index_name": "order_items_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX order_items_pkey ON retail.order_items USING btree (id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "orders",
              "table_type": "BASE TABLE",
              "table_comment": null,
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "shopify_order_id",
                  "ordinal_position": 2,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "order_name",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "source",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'shopify'::text",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "shop_domain",
                  "ordinal_position": 5,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 6,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "affiliate_professional_id",
                  "ordinal_position": 7,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "checkout_session_token",
                  "ordinal_position": 8,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "lifecycle_status",
                  "ordinal_position": 9,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "financial_status",
                  "ordinal_position": 10,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "fulfillment_status",
                  "ordinal_position": 11,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 12,
                  "data_type": "character",
                  "udt_name": "bpchar",
                  "character_maximum_length": 3,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'AUD'::bpchar",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "gross_cents",
                  "ordinal_position": 13,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "refunded_cents",
                  "ordinal_position": 14,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "returned_cents",
                  "ordinal_position": 15,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "net_cents",
                  "ordinal_position": 16,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "ordered_at",
                  "ordinal_position": 17,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "paid_at",
                  "ordinal_position": 18,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "cancelled_at",
                  "ordinal_position": 19,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "closed_at",
                  "ordinal_position": 20,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "customer_email_hash",
                  "ordinal_position": 21,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "customer_region",
                  "ordinal_position": 22,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "shipping_country_code",
                  "ordinal_position": 23,
                  "data_type": "character",
                  "udt_name": "bpchar",
                  "character_maximum_length": 2,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "raw_payload",
                  "ordinal_position": 25,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 26,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "updated_at",
                  "ordinal_position": 27,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "orders_affiliate_professional_id_fkey",
                  "column_name": "affiliate_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "orders_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "orders_affiliate_financial_status_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "affiliate_professional_id",
                    "financial_status",
                    "ordered_at"
                  ],
                  "index_definition": "CREATE INDEX orders_affiliate_financial_status_idx ON retail.orders USING btree (affiliate_professional_id, financial_status, ordered_at DESC)"
                },
                {
                  "index_name": "orders_affiliate_ordered_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "affiliate_professional_id",
                    "ordered_at"
                  ],
                  "index_definition": "CREATE INDEX orders_affiliate_ordered_idx ON retail.orders USING btree (affiliate_professional_id, ordered_at DESC)"
                },
                {
                  "index_name": "orders_brand_financial_status_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "financial_status",
                    "ordered_at"
                  ],
                  "index_definition": "CREATE INDEX orders_brand_financial_status_idx ON retail.orders USING btree (brand_professional_id, financial_status, ordered_at DESC)"
                },
                {
                  "index_name": "orders_brand_ordered_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "brand_professional_id",
                    "ordered_at"
                  ],
                  "index_definition": "CREATE INDEX orders_brand_ordered_idx ON retail.orders USING btree (brand_professional_id, ordered_at DESC)"
                },
                {
                  "index_name": "orders_checkout_session_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "checkout_session_token"
                  ],
                  "index_definition": "CREATE INDEX orders_checkout_session_idx ON retail.orders USING btree (checkout_session_token)"
                },
                {
                  "index_name": "orders_financial_status_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "financial_status",
                    "ordered_at"
                  ],
                  "index_definition": "CREATE INDEX orders_financial_status_idx ON retail.orders USING btree (financial_status, ordered_at DESC)"
                },
                {
                  "index_name": "orders_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX orders_pkey ON retail.orders USING btree (id)"
                },
                {
                  "index_name": "orders_shopify_order_id_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "shopify_order_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX orders_shopify_order_id_uq ON retail.orders USING btree (shopify_order_id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": false,
                "rls_forced": false
              },
              "rls_policies": null
            },
            {
              "table_name": "professional_selections",
              "table_type": "BASE TABLE",
              "table_comment": "Shopify product IDs selected by a professional to display on their Comet site (max 6). Product details fetched from Shopify API at runtime.",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "shopify_product_id",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "sort_order",
                  "ordinal_position": 4,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "0",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "created_at",
                  "ordinal_position": 5,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "enterprise_id",
                  "ordinal_position": 7,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_product_id",
                  "ordinal_position": 8,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "brand_professional_id",
                  "ordinal_position": 9,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "professional_selections_brand_product_id_fkey",
                  "column_name": "brand_product_id",
                  "foreign_schema": "retail",
                  "foreign_table": "brand_products",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "professional_selections_brand_professional_id_fkey",
                  "column_name": "brand_professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                },
                {
                  "fk_name": "professional_selections_enterprise_id_fkey",
                  "column_name": "enterprise_id",
                  "foreign_schema": "core",
                  "foreign_table": "enterprises",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                },
                {
                  "fk_name": "professional_selections_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "professional_selections_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX professional_selections_pkey ON retail.professional_selections USING btree (id)"
                },
                {
                  "index_name": "ps_professional_brand_product_uq",
                  "is_unique": true,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "brand_product_id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX ps_professional_brand_product_uq ON retail.professional_selections USING btree (professional_id, brand_product_id)"
                },
                {
                  "index_name": "ps_professional_brand_sort_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "sort_order",
                    "brand_professional_id"
                  ],
                  "index_definition": "CREATE INDEX ps_professional_brand_sort_idx ON retail.professional_selections USING btree (professional_id, brand_professional_id, sort_order)"
                },
                {
                  "index_name": "ps_professional_enterprise_sort",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "sort_order",
                    "enterprise_id"
                  ],
                  "index_definition": "CREATE INDEX ps_professional_enterprise_sort ON retail.professional_selections USING btree (professional_id, enterprise_id, sort_order)"
                },
                {
                  "index_name": "ps_professional_sort",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "sort_order"
                  ],
                  "index_definition": "CREATE INDEX ps_professional_sort ON retail.professional_selections USING btree (professional_id, sort_order)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": [
                {
                  "policy_name": "ps_anon_read_published",
                  "command": "SELECT",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.sites s\n  WHERE ((s.professional_id = professional_selections.professional_id) AND (s.is_published = true))))",
                  "with_check_expression": null
                },
                {
                  "policy_name": "ps_pro_all",
                  "command": "ALL",
                  "is_permissive": true,
                  "using_expression": "(professional_id = ( SELECT professionals.id\n   FROM core.professionals\n  WHERE ((professionals.auth_user_id = auth.uid()) AND (professionals.deleted_at IS NULL))))",
                  "with_check_expression": "(professional_id = ( SELECT professionals.id\n   FROM core.professionals\n  WHERE ((professionals.auth_user_id = auth.uid()) AND (professionals.deleted_at IS NULL))))"
                },
                {
                  "policy_name": "ps_staff_all",
                  "command": "ALL",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff\n  WHERE (comet_staff.auth_user_id = auth.uid())))",
                  "with_check_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff\n  WHERE (comet_staff.auth_user_id = auth.uid())))"
                }
              ]
            },
            {
              "table_name": "sale_events",
              "table_type": "BASE TABLE",
              "table_comment": "Log of product sales attributed to professionals (Bed & Blade pays commissions directly)",
              "columns": [
                {
                  "column_name": "id",
                  "ordinal_position": 1,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "gen_random_uuid()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "professional_id",
                  "ordinal_position": 2,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "shopify_product_id",
                  "ordinal_position": 3,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "shopify_order_id",
                  "ordinal_position": 4,
                  "data_type": "text",
                  "udt_name": "text",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "quantity",
                  "ordinal_position": 5,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "NO",
                  "column_default": "1",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "sale_amount_cents",
                  "ordinal_position": 6,
                  "data_type": "integer",
                  "udt_name": "int4",
                  "character_maximum_length": null,
                  "numeric_precision": 32,
                  "numeric_scale": 0,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "currency_code",
                  "ordinal_position": 7,
                  "data_type": "character",
                  "udt_name": "bpchar",
                  "character_maximum_length": 3,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "'AUD'::bpchar",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "event_payload",
                  "ordinal_position": 8,
                  "data_type": "jsonb",
                  "udt_name": "jsonb",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": "'{}'::jsonb",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "recorded_at",
                  "ordinal_position": 9,
                  "data_type": "timestamp with time zone",
                  "udt_name": "timestamptz",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "NO",
                  "column_default": "now()",
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                },
                {
                  "column_name": "enterprise_id",
                  "ordinal_position": 10,
                  "data_type": "uuid",
                  "udt_name": "uuid",
                  "character_maximum_length": null,
                  "numeric_precision": null,
                  "numeric_scale": null,
                  "is_nullable": "YES",
                  "column_default": null,
                  "is_identity": "NO",
                  "identity_generation": null,
                  "column_comment": null
                }
              ],
              "primary_keys": [
                "id"
              ],
              "foreign_keys": [
                {
                  "fk_name": "sale_events_enterprise_id_fkey",
                  "column_name": "enterprise_id",
                  "foreign_schema": "core",
                  "foreign_table": "enterprises",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "SET NULL"
                },
                {
                  "fk_name": "sale_events_professional_id_fkey",
                  "column_name": "professional_id",
                  "foreign_schema": "core",
                  "foreign_table": "professionals",
                  "foreign_column": "id",
                  "update_rule": "NO ACTION",
                  "delete_rule": "CASCADE"
                }
              ],
              "unique_constraints": null,
              "indexes": [
                {
                  "index_name": "sale_events_pkey",
                  "is_unique": true,
                  "is_primary": true,
                  "index_columns": [
                    "id"
                  ],
                  "index_definition": "CREATE UNIQUE INDEX sale_events_pkey ON retail.sale_events USING btree (id)"
                },
                {
                  "index_name": "se_enterprise_recorded_idx",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "recorded_at",
                    "enterprise_id"
                  ],
                  "index_definition": "CREATE INDEX se_enterprise_recorded_idx ON retail.sale_events USING btree (enterprise_id, recorded_at DESC)"
                },
                {
                  "index_name": "se_professional_recorded",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "professional_id",
                    "recorded_at"
                  ],
                  "index_definition": "CREATE INDEX se_professional_recorded ON retail.sale_events USING btree (professional_id, recorded_at DESC)"
                },
                {
                  "index_name": "se_shopify_order",
                  "is_unique": false,
                  "is_primary": false,
                  "index_columns": [
                    "shopify_order_id"
                  ],
                  "index_definition": "CREATE INDEX se_shopify_order ON retail.sale_events USING btree (shopify_order_id)"
                }
              ],
              "row_level_security": {
                "rls_enabled": true,
                "rls_forced": false
              },
              "rls_policies": [
                {
                  "policy_name": "se_pro_read_own",
                  "command": "SELECT",
                  "is_permissive": true,
                  "using_expression": "(professional_id = ( SELECT professionals.id\n   FROM core.professionals\n  WHERE ((professionals.auth_user_id = auth.uid()) AND (professionals.deleted_at IS NULL))))",
                  "with_check_expression": null
                },
                {
                  "policy_name": "se_staff_all",
                  "command": "ALL",
                  "is_permissive": true,
                  "using_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff\n  WHERE (comet_staff.auth_user_id = auth.uid())))",
                  "with_check_expression": "(EXISTS ( SELECT 1\n   FROM core.comet_staff\n  WHERE (comet_staff.auth_user_id = auth.uid())))"
                }
              ]
            }
          ],
          "enums": null,
          "functions": [
            {
              "function_name": "enforce_brand_featured_limit",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nDECLARE\n    featured_count integer;\nBEGIN\n    IF COALESCE(NEW.is_featured, false) IS DISTINCT FROM true THEN\n        RETURN NEW;\n    END IF;\n\n    SELECT count(*)\n      INTO featured_count\n      FROM retail.brand_product_settings bps\n     WHERE bps.professional_id = NEW.professional_id\n       AND bps.is_featured = true\n       AND (TG_OP <> 'UPDATE' OR bps.id <> NEW.id);\n\n    IF featured_count >= 10 THEN\n        RAISE EXCEPTION 'A brand may have at most 10 featured products.'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    RETURN NEW;\nEND;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "enforce_max_selections",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nDECLARE\n    current_count integer;\nBEGIN\n    SELECT count(*) INTO current_count\n    FROM retail.professional_selections\n    WHERE professional_id = NEW.professional_id;\n\n    IF current_count >= 10 THEN\n        RAISE EXCEPTION 'Professional may select a maximum of 10 products'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    RETURN NEW;\nEND;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "ensure_brand_product_settings_row",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nBEGIN\n    INSERT INTO retail.brand_product_settings (\n        id,\n        professional_id,\n        brand_product_id,\n        shopify_product_id,\n        is_featured,\n        is_available,\n        sort_order,\n        created_at,\n        updated_at\n    )\n    VALUES (\n        gen_random_uuid(),\n        NEW.brand_professional_id,\n        NEW.id,\n        NEW.shopify_product_id,\n        false,\n        true,\n        0,\n        now(),\n        now()\n    )\n    ON CONFLICT (professional_id, brand_product_id)\n    DO UPDATE SET\n        shopify_product_id = EXCLUDED.shopify_product_id,\n        updated_at = now();\n\n    RETURN NEW;\nEND;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "set_brand_affiliate_segments_updated_at",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nBEGIN\n    NEW.updated_at = NOW();\n    RETURN NEW;\nEND;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "set_brand_promotions_updated_at",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nBEGIN\n    NEW.updated_at = NOW();\n    RETURN NEW;\nEND;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "validate_brand_product_affiliate_override",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nDECLARE\n    product_brand_professional_id uuid;\nBEGIN\n    SELECT bp.brand_professional_id\n      INTO product_brand_professional_id\n      FROM retail.brand_products bp\n     WHERE bp.id = NEW.brand_product_id;\n\n    IF product_brand_professional_id IS NULL THEN\n        RAISE EXCEPTION 'Selected brand product does not exist.'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    IF product_brand_professional_id <> NEW.brand_professional_id THEN\n        RAISE EXCEPTION 'Override brand_professional_id does not match selected brand product owner.'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    IF NOT EXISTS (\n        SELECT 1\n          FROM core.brand_partner_links l\n         WHERE l.affiliate_professional_id = NEW.affiliate_professional_id\n           AND l.brand_professional_id = NEW.brand_professional_id\n    ) THEN\n        RAISE EXCEPTION 'Affiliate is not connected to this brand.'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    RETURN NEW;\nEND;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "validate_brand_product_affiliate_setting",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nDECLARE\n    product_brand_professional_id uuid;\nBEGIN\n    SELECT bp.brand_professional_id\n      INTO product_brand_professional_id\n      FROM retail.brand_products bp\n     WHERE bp.id = NEW.brand_product_id;\n\n    IF product_brand_professional_id IS NULL THEN\n        RAISE EXCEPTION 'Selected brand product does not exist.'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    IF product_brand_professional_id <> NEW.brand_professional_id THEN\n        RAISE EXCEPTION 'Setting brand_professional_id does not match selected brand product owner.'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    IF NOT EXISTS (\n        SELECT 1\n          FROM core.brand_partner_links l\n         WHERE l.affiliate_professional_id = NEW.affiliate_professional_id\n           AND l.brand_professional_id = NEW.brand_professional_id\n    ) THEN\n        RAISE EXCEPTION 'Affiliate is not connected to this brand.'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    RETURN NEW;\nEND;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "validate_order_item_brand",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nDECLARE\n    parent_brand_id uuid;\nBEGIN\n    SELECT o.brand_professional_id\n      INTO parent_brand_id\n      FROM retail.orders o\n     WHERE o.id = NEW.order_id;\n\n    IF parent_brand_id IS NULL THEN\n        RAISE EXCEPTION 'Parent order does not exist for item.'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    IF parent_brand_id <> NEW.brand_professional_id THEN\n        RAISE EXCEPTION 'Order item brand_professional_id must equal parent order brand_professional_id.'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    RETURN NEW;\nEND;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "validate_professional_brand_selection",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nDECLARE\n    product_brand_professional_id uuid;\n    product_shopify_product_id text;\n    product_sync_active boolean;\n    has_allow_override boolean;\n    available_for_affiliate boolean;\nBEGIN\n    SELECT bp.brand_professional_id, bp.shopify_product_id, bp.is_sync_active\n      INTO product_brand_professional_id, product_shopify_product_id, product_sync_active\n      FROM retail.brand_products bp\n     WHERE bp.id = NEW.brand_product_id;\n\n    IF product_brand_professional_id IS NULL THEN\n        RAISE EXCEPTION 'Selected brand product does not exist.'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    IF product_brand_professional_id <> NEW.brand_professional_id THEN\n        RAISE EXCEPTION 'Selected brand product does not belong to provided brand_professional_id.'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    NEW.shopify_product_id = product_shopify_product_id;\n\n    IF product_sync_active IS DISTINCT FROM true THEN\n        RAISE EXCEPTION 'Selected product is not sync-active and cannot be sold.'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    -- 'deny' override takes unconditional precedence.\n    IF EXISTS (\n        SELECT 1\n          FROM retail.brand_product_affiliate_overrides o\n         WHERE o.affiliate_professional_id = NEW.professional_id\n           AND o.brand_product_id = NEW.brand_product_id\n           AND o.override_type = 'deny'\n    ) THEN\n        RAISE EXCEPTION 'Selected product is denied for this affiliate.'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    -- 'allow' override bypasses the brand-level availability flag.\n    SELECT EXISTS (\n        SELECT 1\n          FROM retail.brand_product_affiliate_overrides o\n         WHERE o.affiliate_professional_id = NEW.professional_id\n           AND o.brand_product_id = NEW.brand_product_id\n           AND o.override_type = 'allow'\n    )\n      INTO has_allow_override;\n\n    SELECT EXISTS (\n        SELECT 1\n          FROM retail.brand_product_settings bps\n         WHERE bps.professional_id = NEW.brand_professional_id\n           AND bps.brand_product_id = NEW.brand_product_id\n           AND (has_allow_override OR COALESCE(bps.is_available, true) = true)\n    )\n      INTO available_for_affiliate;\n\n    IF NOT available_for_affiliate THEN\n        RAISE EXCEPTION 'Selected product is not available for this affiliate.'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    IF NOT EXISTS (\n        SELECT 1\n          FROM core.brand_partner_links l\n         WHERE l.affiliate_professional_id = NEW.professional_id\n           AND l.brand_professional_id = NEW.brand_professional_id\n    ) THEN\n        RAISE EXCEPTION 'Professional is not connected to selected brand.'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    RETURN NEW;\nEND;\n",
              "language": "plpgsql"
            },
            {
              "function_name": "validate_selection_enterprise_link",
              "arguments": "",
              "return_type": "trigger",
              "body": "\nDECLARE\n    professional_type text;\n    enterprise_type   text;\n    has_link          boolean;\nBEGIN\n    IF NEW.enterprise_id IS NULL THEN\n        RETURN NEW;\n    END IF;\n\n    SELECT e.enterprise_type\n      INTO enterprise_type\n      FROM core.enterprises e\n     WHERE e.id = NEW.enterprise_id\n       AND e.deleted_at IS NULL;\n\n    IF enterprise_type IS NULL THEN\n        RAISE EXCEPTION 'Selected enterprise does not exist or has been deleted.'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    IF enterprise_type <> 'promoter' THEN\n        RAISE EXCEPTION 'Product selections can only be linked to promoter enterprises.'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    SELECT p.professional_type\n      INTO professional_type\n      FROM core.professionals p\n     WHERE p.id = NEW.professional_id\n       AND p.deleted_at IS NULL;\n\n    IF professional_type IS NULL THEN\n        RAISE EXCEPTION 'Professional does not exist or has been deleted.'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    IF professional_type IN ('ambassador', 'influencer') THEN\n        SELECT EXISTS (\n            SELECT 1\n            FROM core.influencer_promoter_contracts c\n            WHERE c.influencer_professional_id = NEW.professional_id\n              AND c.promoter_enterprise_id = NEW.enterprise_id\n              AND c.status = 'active'\n              AND c.starts_at <= now()\n              AND (c.ends_at IS NULL OR c.ends_at > now())\n        )\n        INTO has_link;\n    ELSE\n        SELECT EXISTS (\n            SELECT 1\n            FROM core.professional_enterprise_memberships m\n            WHERE m.professional_id = NEW.professional_id\n              AND m.enterprise_id = NEW.enterprise_id\n              AND m.starts_at <= now()\n              AND (m.ends_at IS NULL OR m.ends_at > now())\n        )\n        INTO has_link;\n    END IF;\n\n    IF NOT has_link THEN\n        RAISE EXCEPTION 'Professional is not actively linked to this promoter enterprise.'\n            USING ERRCODE = 'check_violation';\n    END IF;\n\n    RETURN NEW;\nEND;\n",
              "language": "plpgsql"
            }
          ]
        }
      ]
    }
  }
]