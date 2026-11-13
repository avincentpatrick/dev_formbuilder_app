-- Trusted server facade. Every operation checks BHW's strict admin role.
create function document_library_api(p_actor uuid, p_operation text, p_payload jsonb default '{}')
returns jsonb language plpgsql set search_path=public,pg_temp as $$
declare
  vid uuid; did uuid; aid uuid; duplicate_id uuid; item jsonb; result jsonb;
  source bigint; job uuid; hash text; skey text;
begin
  if not exists(select 1 from admin_users where user_id=p_actor and role='admin') then
    raise exception 'Admin role required' using errcode='42501';
  end if;
  if p_operation='attempt' then
    insert into document_upload_attempt(uploaded_by,original_filename,byte_count,outcome,safe_reason)
      values(p_actor,left(coalesce(p_payload->>'filename','Upload request'),255),
        (p_payload->>'bytes')::bigint,coalesce(p_payload->>'outcome','receiving'),p_payload->>'reason') returning id into aid;
    return jsonb_build_object('id',aid);
  elsif p_operation='fail_attempt' then
    update document_upload_attempt set outcome='failed',safe_reason='Upload could not be registered; retry safely'
      where id=(p_payload->>'attempt')::uuid and outcome='receiving';
    return '{}';
  elsif p_operation='register' then
    aid:=(p_payload->>'attempt')::uuid;
    perform 1 from document_upload_attempt where id=aid and uploaded_by=p_actor and outcome='receiving' for update;
    if not found then raise exception 'Upload attempt unavailable'; end if;
    hash:=p_payload->>'sha256'; skey:=p_payload->>'storageKey';
    perform pg_advisory_xact_lock(hashtextextended(hash,0));
    select id into duplicate_id from document_version where file_sha256=hash order by uploaded_at limit 1;
    if duplicate_id is not null then
      update document_upload_attempt set outcome='duplicate',duplicate_version_id=duplicate_id,sha256=hash,
        detected_type='application/pdf',byte_count=(p_payload->>'bytes')::bigint where id=aid;
      return jsonb_build_object('duplicate',true,'versionId',duplicate_id);
    end if;
    did:=(p_payload->>'documentId')::uuid;
    if did is null then
      insert into document_catalog(title,issuer,document_type,issuance_number,tags,collection_key,created_by)
        values(p_payload->>'title',nullif(p_payload->>'issuer',''),nullif(p_payload->>'type',''),
          nullif(p_payload->>'number',''),array(select jsonb_array_elements_text(coalesce(p_payload->'tags','[]'))),
          nullif(p_payload->>'collection',''),p_actor) returning id into did;
    else
      perform 1 from document_catalog where id=did for update;
      if not found then raise exception 'Document unavailable'; end if;
    end if;
    if nullif(p_payload->>'supersedes','') is not null and not exists(
      select 1 from document_version where id=(p_payload->>'supersedes')::uuid and document_id=did) then
      raise exception 'Superseded version belongs to another document';
    end if;
    insert into document_file(sha256,storage_key,byte_count) values(hash,skey,(p_payload->>'bytes')::bigint)
      on conflict(sha256) do nothing;
    insert into document_version(document_id,file_sha256,publication_date,effective_date,supersedes_id,uploaded_by)
      values(did,hash,nullif(p_payload->>'publicationDate','')::date,nullif(p_payload->>'effectiveDate','')::date,
        nullif(p_payload->>'supersedes','')::uuid,p_actor) returning id into vid;
    job:=enqueue_document_job(vid,p_actor);
    update document_upload_attempt set outcome='queued',version_id=vid,sha256=hash,detected_type='application/pdf',
      byte_count=(p_payload->>'bytes')::bigint where id=aid;
    insert into document_audit_event(actor_id,document_id,version_id,event) values(p_actor,did,vid,'upload_queued');
    return jsonb_build_object('duplicate',false,'versionId',vid);
  elsif p_operation='list' then
    select coalesce(jsonb_agg(t),'[]') into result from (
      select v.id,v.document_id,c.title,c.issuer,c.document_type,c.tags,c.collection_key,v.uploaded_at,
        v.publication_date,v.effective_date,v.archived,v.availability,v.embedding_status,
        coalesce(j.stage,'queued') as stage,j.progress,j.safe_error
      from document_version v join document_catalog c on c.id=v.document_id
      left join lateral(select stage,progress,safe_error from document_job where version_id=v.id and kind='extract'
        order by created_at desc limit 1) j on true
      where (coalesce(p_payload->>'q','')='' or concat_ws(' ',c.title,c.issuer,c.issuance_number,array_to_string(c.tags,' ')) ilike '%'||(p_payload->>'q')||'%')
        and (coalesce(p_payload->>'issuer','')='' or c.issuer ilike '%'||(p_payload->>'issuer')||'%')
        and (coalesce(p_payload->>'type','')='' or c.document_type=p_payload->>'type')
        and (coalesce(p_payload->>'tag','')='' or (p_payload->>'tag')=any(c.tags))
        and (coalesce(p_payload->>'stage','')='' or j.stage=p_payload->>'stage')
        and (nullif(p_payload->>'from','') is null or v.publication_date >= (p_payload->>'from')::date)
        and (nullif(p_payload->>'to','') is null or v.publication_date <= (p_payload->>'to')::date)
        and (coalesce(p_payload->>'archived','')<>'false' or not v.archived)
      order by v.uploaded_at desc,v.id limit 50 offset least(greatest(coalesce((p_payload->>'offset')::integer,0),0),100000)
    ) t;
    select coalesce(jsonb_agg(t),'[]') into item from (
      select id,original_filename,outcome,safe_reason,version_id,duplicate_version_id,created_at
        from document_upload_attempt order by created_at desc limit 20) t;
    return jsonb_build_object('versions',result,'attempts',item);
  elsif p_operation='selection' then
    select coalesce(jsonb_agg(s.key),'[]') into result from document_version v
      join document_catalog c on c.id=v.document_id join document_extraction e on e.id=v.current_extraction_id
      join doc_source s on s.doc_id=e.source_id
      where not v.archived and v.availability in ('available','available_with_warnings') and s.status='approved'
        and ((p_payload->>'version' is not null and v.id=(p_payload->>'version')::uuid)
          or (p_payload->>'version' is null and c.collection_key=p_payload->>'collection'));
    if jsonb_array_length(result)=0 then raise exception 'No available documents selected'; end if;
    if jsonb_array_length(result)>100 then raise exception 'Select a collection with at most 100 active versions'; end if;
    return result;
  end if;
  vid:=(p_payload->>'version')::uuid;
  select document_id into did from document_version where id=vid for update;
  if not found then raise exception 'Version unavailable'; end if;
  if p_operation='detail' then
    select to_jsonb(t) into result from (
      select v.*,c.title,c.issuer,c.document_type,c.issuance_number,c.tags,c.collection_key,f.storage_key,f.byte_count
        from document_version v join document_catalog c on c.id=v.document_id
        join document_file f on f.sha256=v.file_sha256 where v.id=vid) t;
    select to_jsonb(e) into item from document_extraction e where e.version_id=vid
      and e.id=coalesce(nullif(p_payload->>'extraction','')::uuid,(result->>'current_extraction_id')::uuid);
    result:=result||jsonb_build_object('extraction',item);
    select coalesce(jsonb_agg(t),'[]') into item from (select id,revision,created_at from document_extraction
      where version_id=vid order by revision desc) t;
    result:=result||jsonb_build_object('revisions',item);
    select coalesce(jsonb_agg(t),'[]') into item from (select id,kind,stage,progress,attempts,safe_error,created_at,updated_at
      from document_job where version_id=vid order by created_at desc limit 30) t;
    return result||jsonb_build_object('jobs',item);
  elsif p_operation='metadata' then
    update document_catalog set title=p_payload->>'title',issuer=nullif(p_payload->>'issuer',''),
      document_type=nullif(p_payload->>'type',''),issuance_number=nullif(p_payload->>'number',''),
      tags=array(select jsonb_array_elements_text(coalesce(p_payload->'tags','[]'))),collection_key=nullif(p_payload->>'collection','') where id=did;
    if nullif(p_payload->>'supersedes','') is not null and not exists(select 1 from document_version
      where id=(p_payload->>'supersedes')::uuid and document_id=did and id<>vid) then
      raise exception 'Superseded version must belong to this document';
    end if;
    update document_version set publication_date=nullif(p_payload->>'publicationDate','')::date,
      effective_date=nullif(p_payload->>'effectiveDate','')::date,supersedes_id=nullif(p_payload->>'supersedes','')::uuid where id=vid;
  elsif p_operation='archive' then
    update document_version set archived=(p_payload->>'archived')::boolean where id=vid;
    update doc_source s set status=case when (p_payload->>'archived')::boolean then 'rejected'
      when e.id=v.current_extraction_id and v.availability in ('available','available_with_warnings') then 'approved' else 'rejected' end
      from document_extraction e join document_version v on v.id=e.version_id
      where e.version_id=vid and s.doc_id=e.source_id;
  elsif p_operation='retry' then
    job:=enqueue_document_job(vid,p_actor);
  else
    raise exception 'Unknown library operation';
  end if;
  insert into document_audit_event(actor_id,document_id,version_id,event) values(p_actor,did,vid,p_operation);
  return jsonb_build_object('versionId',vid,'jobId',job);
end;
$$;
revoke all on function document_library_api(uuid,text,jsonb) from public,anon,authenticated;
grant execute on function document_library_api(uuid,text,jsonb) to service_role;

-- Selected-library search is lexical and explicitly scoped to validated current versions.
create function search_library_documents(p_actor uuid,p_keys text[],p_query text,p_limit integer default 6)
returns table(chunk_id bigint,doc_key text,doc_title text,doc_as_of date,page_from integer,page_to integer,
  heading text,content text,char_start integer,char_end integer,matched_by text,score real)
language plpgsql set search_path=public,extensions,pg_temp as $$
begin
  if not exists(select 1 from admin_users where user_id=p_actor and role='admin') then
    raise exception 'Admin role required' using errcode='42501';
  end if;
  if cardinality(p_keys)>100 then raise exception 'Selection too large'; end if;
  return query select c.chunk_id,s.key,s.title,s.as_of,c.page_from,c.page_to,c.heading,c.content,c.char_start,c.char_end,
    'lexical'::text,extensions.similarity(c.content,p_query) from doc_chunk c join doc_source s on s.doc_id=c.doc_id
    join document_extraction e on e.source_id=s.doc_id join document_version v on v.current_extraction_id=e.id
    where s.key=any(p_keys) and s.status='approved' and not v.archived
      and (c.content ilike '%'||p_query||'%' or extensions.similarity(c.content,p_query)>0.01)
    order by extensions.similarity(c.content,p_query) desc,c.chunk_id limit least(greatest(p_limit,1),25);
end;
$$;
revoke all on function search_library_documents(uuid,text[],text,integer) from public,anon,authenticated;
grant execute on function search_library_documents(uuid,text[],text,integer) to service_role;
