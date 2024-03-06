#include "include/client.h"

#include <string>
#include <sys/socket.h>
#include <unistd.h>
#include <openssl/rand.h>
#include <stdexcept>
#include <iostream>

namespace wesrv {
    using namespace std;

    Client::Client(int socket, string address, int port) {
        this->socket = socket;
        this->address = address;
        this->port = port;
        unsigned char nonce[40];
        if(!RAND_bytes(nonce, 40)) {
            throw runtime_error("Error generating nonce");
        }
        this->nonce = string((char *)nonce, 32);
        this->auth = false;
    }

    int Client::getSocket() {
        return this->socket;
    }

    void Client::dumpClient() {
        cout << "Client: " << this->address << ":" << this->port << " Socket: " << this->socket << " Auth: " << this->auth << endl;
    }

    Client::~Client() {
        shutdown(this->socket, SHUT_RDWR);
    }
}