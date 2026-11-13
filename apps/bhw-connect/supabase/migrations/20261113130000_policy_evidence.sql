-- Strict-admin, scoped policy evidence. No browser grants or inferred-KB changes.
create function policy_evidence_api(p_actor uuid,p_operation text,p_payload jsonb default '{}') returns jsonb
language plpgsql set search_path=public,extensions,pg_temp as $$
declare items jsonb; keys text[]; historical boolean:=coalesce((p_payload->>'historical')::boolean,false);
  anchor doc_chunk%rowtype; versions uuid[]; revisions uuid[];
begin
  if not exists(select 1 from admin_users where user_id=p_actor and role='admin') then
    raise exception 'Admin role required' using errcode='42501';
  end if;
  if p_operation='authorize' then return '{}'; end if;
  if p_operation in ('catalog','select') then
    versions:=array(select jsonb_array_elements_text(coalesce(p_payload->'versions','[]'))::uuid);
    revisions:=array(select jsonb_array_elements_text(coalesce(p_payload->'revisions','[]'))::uuid);
    if cardinality(versions)+cardinality(revisions)>100 then raise exception 'Selection limit is 100'; end if;
    select coalesce(jsonb_agg(t),'[]') into items from (
      select c.title,c.issuer,c.document_type,c.issuance_number,c.collection_key,v.id as version_id,
        e.id as extraction_id,s.key as document,e.revision,e.page_count,e.warnings,
        v.publication_date,v.effective_date,v.supersedes_id,v.uploaded_at,v.archived,
        (e.id=v.current_extraction_id) as current_revision
      from document_catalog c join document_version v on v.document_id=c.id
      join document_extraction e on e.version_id=v.id join doc_source s on s.doc_id=e.source_id
      where length(e.canonical_text)>0 and v.availability in ('available','available_with_warnings')
        and (historical or (not v.archived and e.id=v.current_extraction_id))
        and (p_operation='catalog' or
          (coalesce(p_payload->>'mode','active')='active' and e.id=v.current_extraction_id and not v.archived
           and (nullif(p_payload->>'collection','') is null or c.collection_key=p_payload->>'collection')) or
          (p_payload->>'mode'='selected' and ((v.id=any(versions) and e.id=v.current_extraction_id) or e.id=any(revisions))))
      order by v.uploaded_at desc,e.revision desc limit 501
    ) t;
    if p_operation='select' then
      if jsonb_array_length(items)=0 then raise exception 'No available policy text in this selection'; end if;
      if jsonb_array_length(items)>100 then raise exception 'Select at most 100 policy revisions'; end if;
      if p_payload->>'mode'='selected' and (
        exists(select 1 from unnest(versions) x where not exists(select 1 from jsonb_array_elements(items) e where e->>'version_id'=x::text)) or
        exists(select 1 from unnest(revisions) x where not exists(select 1 from jsonb_array_elements(items) e where e->>'extraction_id'=x::text))) then
        raise exception 'A selected version is unavailable or historical selection is disabled';
      end if;
    end if;
    return items;
  end if;
  keys:=array(select jsonb_array_elements_text(coalesce(p_payload->'keys','[]')));
  if cardinality(keys)=0 or cardinality(keys)>100 then raise exception 'Invalid policy scope'; end if;
  if p_operation='read' then
    select c.* into anchor from doc_chunk c join doc_source s on s.doc_id=c.doc_id
      where c.chunk_id=(p_payload->>'chunk')::bigint and s.key=any(keys);
    if not found then raise exception 'Passage outside selected policy scope'; end if;
  end if;
  if p_operation not in ('search','read','inventory') then raise exception 'Unknown evidence operation'; end if;
  select coalesce(jsonb_agg(t),'[]') into items from (
    select c.chunk_id,s.key as document,s.title as document_title,c.page_from as page,c.page_to,
      c.heading,c.content,c.char_start,c.char_end,v.id as version_id,e.id as extraction_id,
      e.revision,v.publication_date,v.effective_date,v.supersedes_id,e.warnings,
      v.archived,(e.id=v.current_extraction_id) as current_revision
    from doc_chunk c join doc_source s on s.doc_id=c.doc_id
    join document_extraction e on e.source_id=s.doc_id join document_version v on v.id=e.version_id
    where s.key=any(keys) and v.availability in ('available','available_with_warnings')
      and (historical or (not v.archived and v.current_extraction_id=e.id and s.status='approved'))
      and (p_operation='inventory' or
        (p_operation='search' and (c.content ilike '%'||(p_payload->>'query')||'%'
          or extensions.similarity(c.content,p_payload->>'query')>0.01)) or
        (p_operation='read' and c.doc_id=anchor.doc_id and c.chunk_index between
          anchor.chunk_index-least(greatest(coalesce((p_payload->>'neighbors')::integer,0),0),2)
          and anchor.chunk_index+least(greatest(coalesce((p_payload->>'neighbors')::integer,0),0),2)))
    order by case when p_operation='search' then extensions.similarity(c.content,p_payload->>'query') else 0 end desc,c.chunk_id
    limit case when p_operation='read' then 5 else least(greatest(coalesce((p_payload->>'limit')::integer,6),1),25) end
  ) t;
  return items;
end;
$$;
revoke all on function policy_evidence_api(uuid,text,jsonb) from public,anon,authenticated;
grant execute on function policy_evidence_api(uuid,text,jsonb) to service_role;
