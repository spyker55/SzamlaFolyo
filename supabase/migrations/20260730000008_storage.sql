-- Private bucket for the original files. Object path: {company_id}/{document_file_id}/{filename}
-- Uploads go through the server (service role); members get read access to
-- their own company's folder for signed-URL-free rendering if ever needed.

insert into storage.buckets (id, name, public)
values ('iratok', 'iratok', false)
on conflict (id) do nothing;

create policy iratok_select on storage.objects
  for select to authenticated
  using (
    bucket_id = 'iratok'
    and (storage.foldername(name))[1] in (
      select id::text from public.company where id in (select app.user_company_ids())
    )
  );
