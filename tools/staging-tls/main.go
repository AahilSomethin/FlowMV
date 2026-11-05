package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"fmt"
	"math/big"
	"net"
	"os"
	"path/filepath"
	"strings"
	"time"
)

func main() {
	if len(os.Args) != 2 {
		panic("Provide the owned staging certificate directory")
	}
	out, err := filepath.Abs(os.Args[1])
	if err != nil {
		panic(err)
	}
	if !strings.HasSuffix(filepath.ToSlash(out), "/backend/storage/app/phase12-staging/certs") {
		panic("Owned staging path required")
	}
	if err = os.MkdirAll(out, 0700); err != nil {
		panic(err)
	}
	for _, name := range []string{"web", "ai"} {
		if _, err := os.Stat(filepath.Join(out, name+".key")); err == nil {
			panic("Existing keys are never overwritten")
		}
		key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
		if err != nil {
			panic(err)
		}
		serial, err := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 128))
		if err != nil {
			panic(err)
		}
		cert := &x509.Certificate{SerialNumber: serial, Subject: pkix.Name{CommonName: "FlowMV isolated staging " + name}, NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(30 * 24 * time.Hour), KeyUsage: x509.KeyUsageDigitalSignature | x509.KeyUsageCertSign, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth}, IsCA: true, BasicConstraintsValid: true, DNSNames: []string{"localhost", "ai-go"}, IPAddresses: []net.IP{net.ParseIP("127.0.0.1")}}
		der, err := x509.CreateCertificate(rand.Reader, cert, cert, &key.PublicKey, key)
		if err != nil {
			panic(err)
		}
		secret, err := x509.MarshalPKCS8PrivateKey(key)
		if err != nil {
			panic(err)
		}
		if err = os.WriteFile(filepath.Join(out, name+".crt"), pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), 0644); err != nil {
			panic(err)
		}
		if err = os.WriteFile(filepath.Join(out, name+".key"), pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: secret}), 0600); err != nil {
			panic(err)
		}
	}
	fmt.Println("Generated dedicated short-lived staging certificates; not production certificates.")
}
