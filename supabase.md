# Supabase Workflow

This document outlines the standard procedures for working with the Supabase project. Following these steps ensures stability, consistency, and safety.

## Database Migration Workflow

This is the **only** approved method for making changes to the database schema. Do not make changes directly in the Supabase SQL Editor. The migration files in `/supabase/migrations` are the absolute source of truth for the database schema.

The process is as follows:

1.  **Create a New Migration File:**
    From your local terminal, generate a new, timestamped migration file. Use a short, descriptive name for the change.
    ```bash
    supabase migrations new <description_of_change>
    ```
    *Example: `supabase migrations new add_user_profiles`*

2.  **Write the SQL:**
    Open the newly created SQL file in `supabase/migrations/` and write the `CREATE TABLE`, `ALTER TABLE`, etc. statements for your schema change.

3.  **Apply to Production:**
    Push the migration to the live Supabase database. The CLI will check which migrations have not yet been applied and run them.
    ```bash
    supabase db push
    ```

4.  **Commit to Git:**
    Once the changes are applied and verified, commit the new migration file to your Git repository. This keeps the project history in sync with the database schema.
    ```bash
    git add supabase/migrations/
    git commit -m "feat(db): Add migration for <description_of_change>"
    ```

## Live Database Workflow (Non-Docker)

This section lists the essential commands for managing a live Supabase project without using Docker for local development.

- `supabase login`: Authenticates you with the Supabase platform.
- `supabase link --project-ref <your-project-ref>`: Links your local repository to your remote Supabase project.
- `supabase migrations new <name>`: Creates a new, empty migration file locally.
- `supabase db push`: Pushes all pending local migrations to the remote database.
- `supabase db remote changes`: Diffs the remote database against your local migrations to see schema changes made outside the migration workflow.
- `supabase functions deploy <function-name>`: Deploys a specific Edge Function to your project.
- `supabase secrets set <NAME=VALUE NAME2=VALUE2>`: Sets one or more secrets for your remote project.
- `supabase secrets list`: Lists all secrets for your remote project (values are not shown).