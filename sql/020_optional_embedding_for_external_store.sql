-- allow 'chunks.embedding' to be NULL when using Pinecone for vector storage
alter table chunks alter column embedding drop not null;