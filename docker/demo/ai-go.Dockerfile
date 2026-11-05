FROM golang:1.27.3-bookworm@sha256:d9b6a1beb9474adc2a1ce842573639b44b2a0dd49b6669f51d3821baf9ec37c9 AS source
WORKDIR /src
COPY services/ai-go/ ./

FROM source AS qa
ENV CGO_ENABLED=1
RUN test -z "$(gofmt -l cmd internal)" && go vet ./... && go test -race -count=1 ./...

FROM source AS build
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /ai-go ./cmd/server

FROM scratch AS runtime
COPY --from=build /ai-go /ai-go
COPY --from=build /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/ca-certificates.crt
USER 65532:65532
ENTRYPOINT ["/ai-go"]
